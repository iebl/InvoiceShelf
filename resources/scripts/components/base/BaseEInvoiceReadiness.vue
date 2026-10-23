<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  /** Whether every requirement is met — the E-Invoice Ready verdict. */
  ready: boolean
  /** The stable requirement identifiers the server named as missing. */
  missingRequirements?: string[]
  /** Headline of the not-ready state. */
  warningTitle?: string
  /** Sentence introducing the list of missing requirements. */
  warningDescription?: string
}

const props = withDefaults(defineProps<Props>(), {
  missingRequirements: () => [],
  warningTitle: '',
  warningDescription: '',
})

const { t, te } = useI18n()

const title = computed<string>(() => props.warningTitle || t('e_invoice.not_ready'))

const description = computed<string>(
  () => props.warningDescription || t('e_invoice.not_ready_desc')
)

/**
 * Label for a requirement identifier. An identifier without a translation is
 * shown as is.
 */
const requirements = computed<string[]>(() =>
  props.missingRequirements.map((requirement) => {
    const key = `e_invoice.requirements.${requirement}`
    return te(key) ? t(key) : requirement
  })
)
</script>

<template>
  <div
    v-if="ready"
    class="flex items-start rounded-md bg-alert-success-bg p-4 text-sm text-alert-success-text"
  >
    <BaseIcon name="CheckCircleIcon" class="w-5 h-5 mr-2 shrink-0" />
    <div>
      <h3 class="font-medium">{{ $t('e_invoice.ready') }}</h3>
      <p class="mt-1">{{ $t('e_invoice.ready_desc') }}</p>
    </div>
  </div>

  <div
    v-else
    class="flex items-start rounded-md bg-alert-warning-bg p-4 text-sm text-alert-warning-text"
  >
    <BaseIcon name="ExclamationTriangleIcon" class="w-5 h-5 mr-2 shrink-0" />
    <div>
      <h3 class="font-medium">{{ title }}</h3>
      <p class="mt-1">{{ description }}</p>
      <ul class="mt-2 list-disc pl-5 space-y-1">
        <li v-for="(requirement, index) in requirements" :key="index">
          {{ requirement }}
        </li>
      </ul>
    </div>
  </div>
</template>
