<?php

namespace App\Http\Controllers\API\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\GenerateReportRequest;
use App\Services\Reports\Exports\ReportExcelExporter;
use App\Services\Reports\Exports\ReportPdfExporter;
use App\Services\Reports\Exports\ReportWordExporter;
use App\Services\Reports\ReportService;
use App\Support\SuperAdminAccess;
use Illuminate\Http\Request;

final class ReportingController extends Controller
{
    public function types(Request $request, ReportService $reports)
    {
        $this->authorizeReports($request);

        return response()->json([
            'data' => $reports->definitions(),
            'statusCode' => 200,
        ]);
    }

    public function filters(Request $request, ReportService $reports)
    {
        $this->authorizeReports($request);
        $input = $request->validate(['report_type' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(app(\App\Services\Reports\ReportDefinitionRegistry::class)->all()))]]);

        return response()->json([
            'data' => $reports->filterOptions($request->user(), $input['report_type'] ?? null),
            'statusCode' => 200,
        ]);
    }

    public function generate(GenerateReportRequest $request, ReportService $reports)
    {
        return response()->json([
            'data' => $reports->generate($request->validated(), $request->user(), true),
            'statusCode' => 200,
        ]);
    }

    public function export(
        GenerateReportRequest $request,
        string $format,
        ReportService $reports,
        ReportExcelExporter $excel,
        ReportPdfExporter $pdf,
        ReportWordExporter $word,
    ) {
        abort_unless(in_array($format, ['xlsx', 'pdf', 'docx'], true), 404);

        $definition = app(\App\Services\Reports\ReportDefinitionRegistry::class)
            ->get($request->validated('report_type'));
        abort_unless(in_array($format, $definition['exports'], true), 422);

        $report = $reports->generate($request->validated(), $request->user(), false);
        $filename = $reports->exportFilename($request->validated(), $format, $request->user());

        return match ($format) {
            'xlsx' => $excel->download($report, $filename),
            'pdf' => $pdf->download($report, $filename),
            'docx' => $word->download($report, $filename),
        };
    }

    private function authorizeReports(Request $request): void
    {
        abort_unless(
            $request->user() !== null
                && SuperAdminAccess::allowed($request->user(), 'View Report'),
            403,
        );
    }
}
