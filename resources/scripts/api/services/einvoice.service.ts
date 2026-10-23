import { client } from '../client'
import { API } from '../endpoints'

/**
 * The E-Invoice Ready verdict for the current company: whether its master data
 * is complete, and the stable requirement identifiers naming what is not.
 */
export interface EInvoiceReadiness {
  available: boolean
  enabled: boolean
  required_pdf_driver: string
  ready: boolean
  missing_requirements: string[]
}

/**
 * The same verdict for one invoice, plus whether it would actually trigger the
 * Fallback — an ordinary PDF instead of a Hybrid PDF.
 */
export interface InvoiceEInvoiceReadiness extends EInvoiceReadiness {
  fallback: boolean
}

export const einvoiceService = {
  async companyReadiness(): Promise<EInvoiceReadiness> {
    const { data } = await client.get(API.EINVOICE_READINESS)
    return data
  },

  async invoiceReadiness(id: number): Promise<InvoiceEInvoiceReadiness> {
    const { data } = await client.get(`${API.INVOICES}/${id}/e-invoice-readiness`)
    return data
  },
}
