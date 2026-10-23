<?php

namespace App\Domains\Sales\Http\Controllers\Company;

use App\Domains\Accounts\Models\Company;
use App\Domains\Sales\Application\EInvoiceReadiness;
use App\Domains\Sales\Models\Invoice;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the user is told about E-Invoicing: whether the company is E-Invoice
 * Ready, and whether one invoice would trigger the Fallback.
 */
class EInvoiceReadinessController extends Controller
{
    public function __construct(private readonly EInvoiceReadiness $readiness) {}

    /**
     * The E-Invoice Ready indicator of the current company: whether its master
     * data is complete, and what is missing when it is not.
     */
    public function company(Request $request): JsonResponse
    {
        $company = Company::findOrFail($request->header('company'));

        // The same authorization the E-Invoice settings tab itself takes: the
        // indicator reports on master data only an owner can fix.
        $this->authorize('manage company', $company);

        return response()->json($this->readiness->forCompany($company));
    }

    /**
     * Whether one invoice would become an E-Invoice, or fall back to an
     * ordinary PDF — the warning shown while the data can still be fixed.
     */
    public function invoice(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json($this->readiness->forInvoice($invoice));
    }
}
