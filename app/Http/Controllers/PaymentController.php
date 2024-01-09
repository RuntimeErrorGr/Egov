<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethodEnum;
use App\Http\Requests\StorePaymentRequest;
use Inertia\Inertia;
use App\Models\Payment;
use App\Models\Currency;
use App\Models\Transaction;
use mikehaertl\wkhtmlto\Pdf as wkhtmltoPdf;
use Exception;
use Illuminate\Http\Request as HttpRequest;
use PDF;

class PaymentController extends Controller
{

    public function index()
    {
        return Inertia::render(
            'Payment/Index',
            [
                'payMethods' => fn () => PaymentMethodEnum::getValues(),
                'currencies' => fn () => Currency::all(),
            ]
        );
    }

    public function store(StorePaymentRequest $request)
    {
        $data = $request->validated();
        $payment = new Payment();
        $currency = Currency::where('abbreviation', $data['currency'])->first();
        $payment->currency_id = $currency->id;
        $payment->recipient_name = $data['recipient_name'];
        $payment->recipient_iban = $data['recipient_iban'];
        $payment->method = $data['method'];
        $payment->amount = $data['amount'];
        $payment->tax = $data['tax'];
        $payment->exchange_rate = $data['exchange_rate'];
        $payment->reference = $data['description'];
        $payment->sender_name = $data['sender_name'];
        $payment->sender_phone = $data['sender_phone'];
        $payment->save();

        $transaction = new Transaction();
        $transaction->pay_id = $payment->id; // Link to the payment entry
        $transaction->card_number = $data['card_number'];
        $transaction->holder = $data['holder'];
        $transaction->exp_date = $data['exp_date'];
        $transaction->cvv = $data['cvv'];
        $transaction->bank_number = $data['bank_number'];
        $transaction->wire_number = $data['wire_number'];
        $transaction->paypal_number = $data['paypal_number'];
        $transaction->swift_code = $data['swift_code'];
        $transaction->save();
        $notification = [
            'type' => 'success',
            'title' => 'Payment successfully processed',
        ];

        return back()->with(['notification' => $notification, 'data' => $data]);
    }

    public function download(HttpRequest $request)
    {
        $data = $request->input('data');
        try {
            $pdf = PDF::loadView('pdf.payment_receipt', ['data' => $data]);

            // Generate a unique filename for the PDF
            $filename = 'payment_receipt_' . time() . '.pdf';

            $pdf->save(storage_path('pdf/' . $filename));

            return response()->download(storage_path('pdf/' . $filename), $filename)->deleteFileAfterSend();
        } catch (Exception $e) {
            $notification = [
                'type' => 'error',
                'title' => 'Error generating PDF',
                'message' => $e->getMessage(),
            ];
            return back()->with(['notification' => $notification]);
        }
    }

    protected function getNumberOfPaymentsByCurrencyChart($payments)
    {
        // Get the number of payments by currency abbreviation like ['EUR' => 2, 'USD' => 1]
        $numberOfPaymentsByCurrency = $payments->groupBy('currency.abbreviation')->map(fn ($item) => $item->count());

        // Make the chart configuration as an object with type, data, labels, datasets properties
        $chartConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => $numberOfPaymentsByCurrency->keys(),
                'datasets' => [
                    [
                        'data' => $numberOfPaymentsByCurrency->values(),
                        'backgroundColor' => ["blue", "green", "red", "purple", "yellow", "orange", "pink", "brown", "gray", "black", "cyan", "magenta", "lime", "teal", "aqua", "maroon", "olive", "navy", "fuchsia", "silver"],
                    ],
                ],
            ],
            'options' => [
                'legend' => [
                    'display' => false,
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Number of payments by currency',
                ],
                'scales' => [
                    'yAxes' => [
                        [
                            'ticks' => [
                                'beginAtZero' => true,
                                'stepSize' => 1,
                            ],
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Number of payments',
                            ],
                        ],
                    ],
                    'xAxes' => [
                        [
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Currency',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Convert the chart configuration to JSON
        $chartConfig = json_encode($chartConfig);
        $chartUrl = 'https://quickchart.io/chart?w=500&h=300&c=' . urlencode($chartConfig);

        return $chartUrl;
    }

    protected function getNumberOfTransactionsByMethodChart($payments)
    {
        // Get the number of transactions by payment method like ['card' => 2, 'bank' => 1]
        $numberOfTransactionsByMethod = $payments->groupBy('method')->map(fn ($item) => $item->count());

        // Make the chart configuration as an object with type, data, labels, datasets properties
        $chartConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => $numberOfTransactionsByMethod->keys(),
                'datasets' => [
                    [
                        'data' => $numberOfTransactionsByMethod->values(),
                        'backgroundColor' => [
                            "blue", "green", "red", "purple", "yellow", "orange", "pink"
                        ]
                    ],
                ],
            ],
            'options' => [
                'legend' => [
                    'display' => false,
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Number of transactions by payment method',
                ],
                'scales' => [
                    'yAxes' => [
                        [
                            'ticks' => [
                                'beginAtZero' => true,
                                'stepSize' => 1,
                            ],
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Number of transactions',
                            ],
                        ],
                    ],
                    'xAxes' => [
                        [
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Payment method',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Convert the chart configuration to JSON
        $chartConfig = json_encode($chartConfig);
        $chartUrl = 'https://quickchart.io/chart?w=500&h=300&c=' . urlencode($chartConfig);

        return $chartUrl;
    }

    public function aggregateArray($originalArray)
    {

        $transformedArray = [];
        foreach ($originalArray as $timestamp => $value) {
            // Extract the date part (YYYY-MM-DD) from the timestamp
            $date = substr($timestamp, 0, 10);

            // If the date is not in the transformed array, initialize it
            if (!isset($transformedArray[$date])) {
                $transformedArray[$date] = 0.0;
            }

            // Add the value to the corresponding date
            $transformedArray[$date] += $value;
        }

        return collect($transformedArray);
    }

    protected function getAmountAndTaxesPaidByTimeChart($payments)
    {
        $amountPaidByTime = $this->aggregateArray($payments->groupBy('created_at')->map(fn ($item) => $item->sum('amount'))->toArray());

        $taxesPaidByTime = $this->aggregateArray($payments->groupBy('created_at')->map(fn ($item) => $item->sum('tax'))->toArray());

        $chartConfig = [
            'type' => 'line',
            'data' => [
                'labels' => $amountPaidByTime->keys(),
                'datasets' => [
                    [
                        'label' => 'Amount paid',
                        'data' => $amountPaidByTime->values(),
                        'fill' => false,
                        'borderColor' => 'blue',
                        'backgroundColor' => 'blue',
                        'borderWidth' => 1,
                    ],
                    [
                        'label' => 'Taxes paid',
                        'data' => $taxesPaidByTime->values(),
                        'fill' => false,
                        'borderColor' => 'red',
                        'backgroundColor' => 'red',
                        'borderWidth' => 1,
                    ],
                ],
            ],
            'options' => [
                'legend' => [
                    'display' => true,
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Amount and taxes paid by time',
                ],
                'scales' => [
                    'yAxes' => [
                        [
                            'ticks' => [
                                'beginAtZero' => true,
                                'type' => 'logarithmic',
                            ],
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Amount (RON)',
                            ],
                        ],
                    ],
                    'xAxes' => [
                        [
                            'scaleLabel' => [
                                'display' => true,
                                'labelString' => 'Time period',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $chartConfig = json_encode($chartConfig);
        $chartUrl = 'https://quickchart.io/chart?w=500&h=300&c=' . urlencode($chartConfig);

        return $chartUrl;
    }

    protected function getAmountPaidByCurrencyChart($payments)
    {
        $amountPaidByCurrency = $payments->groupBy('currency.abbreviation')->map(fn ($item) => $item->sum('amount'))->sortDesc()->take(4);

        $chartConfig = [
            'type' => 'pie',
            'data' => [
                'labels' => $amountPaidByCurrency->keys(),
                'datasets' => [
                    [
                        'data' => $amountPaidByCurrency->values(),
                        'backgroundColor' => [
                            "blue", "green", "red", "purple", "yellow", "orange", "pink", "brown", "gray", "black", "cyan", "magenta", "lime", "teal", "aqua", "maroon", "olive", "navy", "fuchsia", "silver",
                        ]
                    ],
                ],
            ],
            'options' => [
                'legend' => [
                    'display' => true,
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Amount paid by currency',
                ],
            ],
        ];

        $chartConfig = json_encode($chartConfig);
        $chartUrl = 'https://quickchart.io/chart?w=500&h=300&c=' . urlencode($chartConfig);

        return $chartUrl;
    }


    protected function getTaxesPaidByCurrencyChart($payments)
    {
        $taxesPaidByCurrency = $payments->groupBy('currency.abbreviation')->map(fn ($item) => $item->sum('tax'))->sortDesc()->take(4);

        $chartConfig = [
            'type' => 'pie',
            'data' => [
                'labels' => $taxesPaidByCurrency->keys(),
                'datasets' => [
                    [
                        'data' => $taxesPaidByCurrency->values(),
                        'backgroundColor' => [
                            "blue", "green", "red", "purple", "yellow", "orange", "pink", "brown", "gray", "black", "cyan", "magenta", "lime", "teal", "aqua", "maroon", "olive", "navy", "fuchsia", "silver",
                        ]
                    ],
                ],
            ],
            'options' => [
                'legend' => [
                    'display' => true,
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Taxes paid by currency',
                ],
            ],
        ];

        $chartConfig = json_encode($chartConfig);
        $chartUrl = 'https://quickchart.io/chart?w=500&h=300&c=' . urlencode($chartConfig);

        return $chartUrl;
    }

    public function analytics()
    {
        // Get the payments info with the related currency abbreviation and the related transaction info
        $payments = Payment::with(['currency', 'transaction'])->get();

        $currencyWithMostPayments = $payments->groupBy('currency.name')->map(fn ($item) => $item->count())->sortDesc()->keys()->first();
        $currencyWithMostPaymentsPercentage = $payments->groupBy('currency.name')->map(fn ($item) => $item->count())->sortDesc()->values()->first() / $payments->count() * 100;
        $methodWithMostTransactions = $payments->groupBy('method')->map(fn ($item) => $item->count())->sortDesc()->keys()->first();
        $methodWithMostPaymentsPercentage = $payments->groupBy('method')->map(fn ($item) => $item->count())->sortDesc()->values()->first() / $payments->count() * 100;
        $currencyWithMostAmountPaid = $payments->groupBy('currency.name')->map(fn ($item) => $item->sum('amount'))->sortDesc()->keys()->first();
        $currencyWithMostTaxesPaid = $payments->groupBy('currency.name')->map(fn ($item) => $item->sum('tax'))->sortDesc()->keys()->first();
        $currencyWithMostAmountPaidPercentage = $payments->groupBy('currency.name')->map(fn ($item) => $item->sum('amount'))->sortDesc()->values()->first() / $payments->sum('amount') * 100;
        $currencyWithMostTaxesPaidPercentage = $payments->groupBy('currency.name')->map(fn ($item) => $item->sum('tax'))->sortDesc()->values()->first() / $payments->sum('tax') * 100;
        $totalAmountPaid = $payments->sum('amount');
        $totalTaxesPaid = $payments->sum('tax');

        if ($payments->isEmpty()) {
            $notification = [
                'type' => 'error',
                'title' => 'Analytics not available',
                'description' => 'There are no data to show analytics for.',
            ];
            return back()->with(['notification' => $notification]);
        }

        $charts = [
            'numberOfPaymentsByCurrencyChart' => $this->getNumberOfPaymentsByCurrencyChart($payments),
            'numberOfTransactionsByMethodChart' => $this->getNumberOfTransactionsByMethodChart($payments),
            'amountAndTaxesPaidByTimeChart' => $this->getAmountAndTaxesPaidByTimeChart($payments),
            'amountPaidByCurrencyChart' => $this->getAmountPaidByCurrencyChart($payments),
            'taxesPaidByCurrencyChart' => $this->getTaxesPaidByCurrencyChart($payments),
        ];

        $data = [
            'charts' => $charts,
            'numberTotalPayments' => $payments->count(),
            'currencyWithMostPayments' => $currencyWithMostPayments,
            'currencyWithMostPaymentsPercentage' => round($currencyWithMostPaymentsPercentage, 3),
            'methodWithMostTransactions' => $methodWithMostTransactions,
            'methodWithMostPaymentsPercentage' => round($methodWithMostPaymentsPercentage, 3),
            'currencyWithMostAmountPaid' => $currencyWithMostAmountPaid,
            'currencyWithMostTaxesPaid' => $currencyWithMostTaxesPaid,
            'currencyWithMostAmountPaidPercentage' => round($currencyWithMostAmountPaidPercentage, 3),
            'currencyWithMostTaxesPaidPercentage' => round($currencyWithMostTaxesPaidPercentage, 3),
            'totalAmountPaid' => round($totalAmountPaid, 3),
            'totalTaxesPaid' => round($totalTaxesPaid, 3),
            'timestamp' => date('d-m-Y H:i:s'),
            'timezone' => date_default_timezone_get(),
        ];

        try {
            $renderPageOne = view('pdf.analytics_summary', ['data' => $data])->render();

            $pdf = new wkhtmltoPdf;
            $pdf->addPage($renderPageOne);

            $filename = 'analytics_summary_' . time() . '.pdf';
            $pdf->saveAs(storage_path('pdf/' . $filename));

            return response()->download(storage_path('pdf/' . $filename), $filename)->deleteFileAfterSend();
        } catch (Exception $e) {
            $notification = [
                'type' => 'error',
                'title' => 'Error generating analytics summary PDF',
                'message' => $e->getMessage(),
            ];
            return back()->with(['notification' => $notification]);
        }
    }
}
