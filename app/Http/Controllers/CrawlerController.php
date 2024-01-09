<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Saasscaleup\LSL\Facades\LSLFacade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use App\Jobs\CrawlWebsiteJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CrawlerController extends Controller
{
    public function index()
    {

        // Get files from storage/app/public with extensions ['.xlsx', '.xlsm', '.xltx', '.xls', '.xlt', '.ods']
        $files = collect(Storage::files('public'))->filter(function ($file) {
            return in_array(pathinfo($file, PATHINFO_EXTENSION), ['xlsx', 'xlsm', 'xltx', 'xls', 'xlt', 'ods']);
        });

        return Inertia::render(
            'Crawler/Index',
            [
                'files' => $files->all(),
                'filesCount' => $files->count(),
            ]
        );
    }

    public function crawl()
    {
        $websites = [
            'https://anmcs.gov.ro/web/',
            'https://ina.gov.ro/',
            'https://mfe.gov.ro/',
            'https://portal.rna.ro'
            // Add more websites to crawl as needed
        ];

        // Clear the cache
        Cache::forget('visitedUrls');

        foreach ($websites as $website) {
            $visitedUrls = [$website];
            $this->crawlOneWebsite($website, $visitedUrls);
        }

        return back();
    }

    public function crawlOneWebsite($url)
    {
        $visitedUrls = Cache::get('visitedUrls', []);

        $fileKeywords = [
            'PAAP',
            'P.A.A.P',
            'PAP',
            'Paap',
            'Pap',
            'pap',
            'paap',
            'achizitii publice',
            'achiziții publice',
            'achizițiilor publice',
            'achizitiilor publice',
            'Achizitii publice',
            'Achiziții publice',
            'Achizițiilor publice',
            'Achizitiilor publice',
            'Achizițiilor Publice',
            'Programul',
            'Program',
            'programul',
            'program',
            'interes',
            'Interes',
            'Informații',
            'Informatii',
            'Public',
            'public',
        ];
        $fileExtensions = ['.xlsx', '.xlsm', '.xltx', '.xls', '.xlt', '.ods'];

        $urlKeywords = [
            'achizitii',
            'informații',
            'informatii',
            'programul',
            'program',
            'public',
            'publice',
            'interes-public'
        ];

        $httpClient = HttpClient::create([
            // do not verify SSL certificates
            'verify_peer' => false,
            'verify_host' => false,
        ]);
        try {
            $fileContent = $httpClient->request('GET', $url)->getContent();
        } catch (\Exception $e) {
            // Skip the website if it cannot be crawled
            Log::info('Could not crawl ' . $url);
            return;
        }
        $crawler = new Crawler($fileContent, $url);
        Log::info('Crawling ' . $url);

        $fileLinks = $crawler->filterXPath(
            '//a[
                (contains(.//text(), "' . implode('") or contains(.//text(), "', $fileKeywords) . '"))
                and 
                (
                    contains(@href, "' . implode('") or contains(@href, "', $fileExtensions) . '")
                )
            ]'
        )->links();

        if (count($fileLinks) > 0) {
            Log::info('Found ' . count($fileLinks) . ' files on ' . $url);
            foreach ($fileLinks as $fileLink) {
                $fileUrl = $fileLink->getUri();
                try {
                    $fileContent = $httpClient->request('GET', $fileUrl)->getContent();

                    // if character "%" is present in the file name, replace it
                    $fileUrl = str_replace('%', '-', $fileUrl);
                    $filename = pathinfo($fileUrl, PATHINFO_BASENAME);
                    Storage::put("public/{$filename}", $fileContent);
                    Log::info('Saved ' . $filename);
                } catch (\Exception $e) {
                    // Skip the file if it cannot be downloaded
                    Log::info('Could not download ' . $fileUrl);
                    continue;
                }
            }
        } else {
            // Continue crawling all href tags
            $links = $crawler->filterXPath(
                '//a[
                    (contains(.//text(), "' . implode('") or contains(.//text(), "', $fileKeywords) . '"))
                    and 
                    (
                        contains(@href, "' . implode('") or contains(@href, "', $urlKeywords) . '")
                    )
                ]'
            )->links();

            foreach ($links as $link) {
                $nextUrl = $link->getUri();

                // Check if the link leads to a different domain to avoid infinite loops
                $parsedUrl = parse_url($nextUrl);
                $currentDomain = parse_url($url)['host'];

                if ($parsedUrl && array_key_exists('host', $parsedUrl) && $parsedUrl['host'] === $currentDomain && !in_array($nextUrl, $visitedUrls)) {
                    $visitedUrls[] = $nextUrl;
                    Cache::put('visitedUrls', $visitedUrls, now()->addHours(24));
                    CrawlWebsiteJob::dispatch($nextUrl)->onQueue('web_crawler');
                }
            }
        }
    }

    public function download(Request $request)
    {
        $filename = $request->input('filename');
        $filePath = storage_path("app/public/{$filename}");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $process = new Process(['libreoffice', '--headless', '--convert-to', 'pdf', $filePath, '--outdir', storage_path('app/public')]);
        $process->run();

        if (!$process->isSuccessful()) {
            return response()->json(['error' => 'Could not convert file'], 500);
        }

        $pdfFilename = pathinfo($filename, PATHINFO_FILENAME) . '.pdf';
        $pdfFilePath = storage_path("app/public/{$pdfFilename}");

        return response()->download($pdfFilePath, $pdfFilename);
    }
}
