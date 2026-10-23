<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useVuelidate } from '@vuelidate/core'
import { helpers, maxLength } from '@vuelidate/validators'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { einvoiceService } from '@/scripts/api/services/einvoice.service'
import type { EInvoiceReadiness } from '@/scripts/api/services/einvoice.service'

const { t } = useI18n()
const companyStore = useCompanyStore()
const globalStore = useGlobalStore()

/**
 * E-Invoicing produces a Hybrid PDF, which only the Gotenberg PDF driver can
 * build. The server decides whether this instance qualifies; the tab only
 * renders the verdict.
 */
const isAvailable = computed<boolean>(() => globalStore.eInvoice?.available === true)

const requiredPdfDriver = computed<string>(() => {
  const driver = globalStore.eInvoice?.required_pdf_driver ?? 'gotenberg'
  return driver.charAt(0).toUpperCase() + driver.slice(1)
})

const isSaving = ref<boolean>(false)

/**
 * The E-Invoice Ready indicator. The server derives it from the same
 * missing-requirements check the invoice pipeline uses, so what it lists here
 * is exactly what would otherwise send every invoice into the Fallback.
 */
const readiness = ref<EInvoiceReadiness | null>(null)

async function loadReadiness(): Promise<void> {
  try {
    readiness.value = await einvoiceService.companyReadiness()
  } catch {
    // Nothing to indicate then — the tab stays usable without the verdict.
    readiness.value = null
  }
}

onMounted(loadReadiness)

const settingsForm = reactive<{
  einvoice_iban: string
  einvoice_bic: string
  einvoice_bank_name: string
}>({
  einvoice_iban: companyStore.selectedCompanySettings.einvoice_iban ?? '',
  einvoice_bic: companyStore.selectedCompanySettings.einvoice_bic ?? '',
  einvoice_bank_name: companyStore.selectedCompanySettings.einvoice_bank_name ?? '',
})

const IBAN_PATTERN = /^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/
const BIC_PATTERN = /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/

function normalizeCode(value: string): string {
  return value.replace(/\s+/g, '').toUpperCase()
}

/**
 * Both codes stay optional here — the E-Invoice Ready indicator is what tells
 * a company that it is still missing master data. What we do reject is a value
 * that could never be a valid IBAN or BIC.
 */
const rules = computed(() => ({
  einvoice_iban: {
    iban: helpers.withMessage(t('settings.e_invoice.iban_invalid'), (value: string) =>
      !helpers.req(value) || IBAN_PATTERN.test(normalizeCode(value))
    ),
  },
  einvoice_bic: {
    bic: helpers.withMessage(t('settings.e_invoice.bic_invalid'), (value: string) =>
      !helpers.req(value) || BIC_PATTERN.test(normalizeCode(value))
    ),
  },
  einvoice_bank_name: {
    maxLength: helpers.withMessage(
      t('settings.e_invoice.bank_name_max_length'),
      maxLength(255)
    ),
  },
}))

const v$ = useVuelidate(
  rules,
  computed(() => settingsForm)
)

const isEnabled = computed<boolean>({
  get: () => companyStore.selectedCompanySettings.einvoice_enabled === 'YES',
  set: async (newValue: boolean) => {
    if (!isAvailable.value) return

    try {
      await companyStore.updateCompanySettings({
        data: { settings: { einvoice_enabled: newValue ? 'YES' : 'NO' } },
        message: 'general.setting_updated',
      })
    } catch {
      // The store already surfaced the failure; the switch falls back to the
      // unchanged store value on its own.
    }
  },
})

async function submitForm(): Promise<void> {
  if (!isAvailable.value) return

  v$.value.$touch()
  if (v$.value.$invalid) return

  isSaving.value = true

  settingsForm.einvoice_iban = normalizeCode(settingsForm.einvoice_iban)
  settingsForm.einvoice_bic = normalizeCode(settingsForm.einvoice_bic)

  try {
    await companyStore.updateCompanySettings({
      data: {
        settings: {
          einvoice_iban: settingsForm.einvoice_iban,
          einvoice_bic: settingsForm.einvoice_bic,
          einvoice_bank_name: settingsForm.einvoice_bank_name,
        },
      },
      message: 'general.setting_updated',
    })

    // The IBAN is master data the indicator judges, so the verdict is re-read
    // rather than left stale next to the value that just changed it.
    await loadReadiness()
  } catch {
    // The store already surfaced the failure.
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <BaseSettingCard
    :title="$t('settings.e_invoice.title')"
    :description="$t('settings.e_invoice.description')"
  >
    <div
      v-if="!isAvailable"
      class="
        mt-6
        flex
        items-start
        rounded-md
        bg-alert-warning-bg
        p-4
        text-sm text-alert-warning-text
      "
    >
      <BaseIcon name="ExclamationTriangleIcon" class="w-5 h-5 mr-2 shrink-0" />
      <span>
        {{
          $t('settings.e_invoice.requires_pdf_driver', {
            driver: requiredPdfDriver,
          })
        }}
      </span>
    </div>

    <BaseEInvoiceReadiness
      v-if="readiness"
      class="mt-6"
      :ready="readiness.ready"
      :missing-requirements="readiness.missing_requirements"
    />

    <ul class="mt-6 divide-y divide-line-default">
      <BaseSwitchSection
        v-model="isEnabled"
        :disabled="!isAvailable"
        :title="$t('settings.e_invoice.enable')"
        :description="$t('settings.e_invoice.enable_desc')"
      />
    </ul>

    <BaseDivider class="mt-2 mb-6" />

    <form action="" @submit.prevent="submitForm">
      <h6 class="text-heading text-lg font-medium">
        {{ $t('settings.e_invoice.bank_details') }}
      </h6>
      <p class="mt-1 mb-6 text-sm text-muted">
        {{ $t('settings.e_invoice.bank_details_desc') }}
      </p>

      <BaseInputGrid>
        <BaseInputGroup
          :label="$t('settings.e_invoice.iban')"
          :error="v$.einvoice_iban.$error && v$.einvoice_iban.$errors[0]?.$message"
        >
          <BaseInput
            v-model.trim="settingsForm.einvoice_iban"
            :disabled="!isAvailable"
            :invalid="v$.einvoice_iban.$error"
            name="einvoice_iban"
            @input="v$.einvoice_iban.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.e_invoice.bic')"
          :error="v$.einvoice_bic.$error && v$.einvoice_bic.$errors[0]?.$message"
        >
          <BaseInput
            v-model.trim="settingsForm.einvoice_bic"
            :disabled="!isAvailable"
            :invalid="v$.einvoice_bic.$error"
            name="einvoice_bic"
            @input="v$.einvoice_bic.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.e_invoice.bank_name')"
          :error="
            v$.einvoice_bank_name.$error &&
            v$.einvoice_bank_name.$errors[0]?.$message
          "
        >
          <BaseInput
            v-model.trim="settingsForm.einvoice_bank_name"
            :disabled="!isAvailable"
            :invalid="v$.einvoice_bank_name.$error"
            name="einvoice_bank_name"
            @input="v$.einvoice_bank_name.$touch()"
          />
        </BaseInputGroup>
      </BaseInputGrid>

      <BaseButton
        :disabled="isSaving || !isAvailable"
        :loading="isSaving"
        type="submit"
        class="mt-6"
      >
        <template #left="slotProps">
          <BaseIcon :class="slotProps.class" name="ArrowDownOnSquareIcon" />
        </template>
        {{ $t('general.save') }}
      </BaseButton>
    </form>
  </BaseSettingCard>
</template>
