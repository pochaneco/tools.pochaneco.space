<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    private const DEFAULT_DURATION_SECONDS = 180;
    private const DEFAULT_DURATION_SECONDS_TEST = 2;
    private const MIN_DURATION_SECONDS = 1;

    private const DEFAULT_INTERVAL_MS = 800;
    private const DEFAULT_INTERVAL_MS_TEST = 100;
    private const MIN_INTERVAL_MS = 50;

    private const TIME_LIMIT_PADDING_SECONDS = 5;

    private const MIN_MESSAGE_LEN = 6;
    private const MAX_MESSAGE_LEN = 18;

    private const MSEC_TO_USEC = 1000;

    private const EVENT_END = 'end';
    private const EVENT_FINISHED = 'finished';

    public function index(): \Inertia\Response
    {
        return Inertia::render('Chat/Index');
    }

    public function stream(Request $request): StreamedResponse
    {
        $defaultDuration = app()->environment('testing') ? self::DEFAULT_DURATION_SECONDS_TEST : self::DEFAULT_DURATION_SECONDS;
        $defaultInterval = app()->environment('testing') ? self::DEFAULT_INTERVAL_MS_TEST : self::DEFAULT_INTERVAL_MS;

        $duration = max(self::MIN_DURATION_SECONDS, (int) $request->integer('duration', $defaultDuration)); // seconds
        $intervalMs = max(self::MIN_INTERVAL_MS, (int) $request->integer('interval', $defaultInterval)); // ms between events
        $maxEvents = (int) $request->integer('events', 0); // optional hard cap

        $response = new StreamedResponse(function () use ($duration, $intervalMs, $maxEvents) {
            $start = microtime(true);
            $sent = 0;

            // Disable output buffering layers if possible
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');
            @set_time_limit($duration + self::TIME_LIMIT_PADDING_SECONDS);

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                $elapsed = microtime(true) - $start;
                if ($elapsed >= $duration) {
                    // Backward compatibility: keep 'end' event
                    echo "event: " . self::EVENT_END . "\n";
                    echo "data: {\"message\":\"stream finished\"}\n\n";

                    // New explicit final event
                    echo "event: " . self::EVENT_FINISHED . "\n";
                    echo "data: {\"message\":\"finished\"}\n\n";

                    @ob_flush();
                    @flush();
                    break;
                }

                if ($maxEvents > 0 && $sent >= $maxEvents) {
                    // Backward compatibility: keep 'end' event
                    echo "event: " . self::EVENT_END . "\n";
                    echo "data: {\"message\":\"max events reached\"}\n\n";

                    // New explicit final event
                    echo "event: " . self::EVENT_FINISHED . "\n";
                    echo "data: {\"message\":\"finished\"}\n\n";

                    @ob_flush();
                    @flush();
                    break;
                }

                $payload = [
                    'id' => Str::uuid()->toString(),
                    'text' => Str::random(random_int(self::MIN_MESSAGE_LEN, self::MAX_MESSAGE_LEN)),
                    'ts' => now()->toISOString(),
                ];

                echo 'data: ' . json_encode($payload) . "\n\n";
                $sent++;

                @ob_flush();
                @flush();

                usleep($intervalMs * self::MSEC_TO_USEC);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // For Nginx

        return $response;
    }
}
