<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyCurlTransport implements LegacyRemoteTransport
{
    /**
     * @return array{follow_redirects: int, verify_peer: int, verify_host: int, connect_timeout_ms: int, request_timeout_ms: int}
     */
    public static function defaultOptions(float $connectTimeoutSeconds, float $requestTimeoutSeconds): array
    {
        return [
            'follow_redirects' => 0,
            'verify_peer' => 1,
            'verify_host' => 2,
            'connect_timeout_ms' => (int) round($connectTimeoutSeconds * 1000),
            'request_timeout_ms' => (int) round($requestTimeoutSeconds * 1000),
        ];
    }

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        if ($method !== LegacyRemoteProtocol::HTTP_METHOD) {
            throw LegacyRemoteClientResult::failure('configuration', 'remote_bridge_invalid_method', 'Remote Bridge legacy transport only supports POST.');
        }

        $curl = curl_init($endpoint);

        if ($curl === false) {
            throw LegacyRemoteClientResult::uncertainTransportFailure();
        }

        $responseHeaders = [];
        $responseBody = '';
        $responseTooLarge = false;
        $options = self::defaultOptions($connectTimeoutSeconds, $requestTimeoutSeconds);

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => LegacyRemoteProtocol::HTTP_METHOD,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $this->flattenHeaders($headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => $options['connect_timeout_ms'],
            CURLOPT_TIMEOUT_MS => $options['request_timeout_ms'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, $headerLine) use (&$responseHeaders) {
                $length = strlen($headerLine);
                $trimmed = trim($headerLine);

                if ($trimmed === '' || strpos($trimmed, ':') === false) {
                    return $length;
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $normalized = strtolower(trim($name));

                if ($normalized !== '') {
                    $responseHeaders[$normalized][] = trim($value);
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, $chunk) use (&$responseBody, &$responseTooLarge) {
                $length = strlen($chunk);
                $remaining = LegacyRemoteProtocol::MAX_BODY_BYTES + 1 - strlen($responseBody);

                if ($remaining > 0) {
                    $responseBody .= substr($chunk, 0, $remaining);
                }

                if (strlen($responseBody) > LegacyRemoteProtocol::MAX_BODY_BYTES) {
                    $responseTooLarge = true;

                    return 0;
                }

                return $length;
            },
        ]);

        $bodyResult = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($responseTooLarge) {
            throw LegacyRemoteClientResult::failure('protocol', 'remote_bridge_response_too_large', 'Remote Bridge response exceeds the maximum protocol size.');
        }

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw LegacyRemoteClientResult::uncertainTransportFailure();
        }

        if ($bodyResult === false || $errno !== 0) {
            throw LegacyRemoteClientResult::uncertainTransportFailure();
        }

        return new LegacyRemoteTransportResponse($status, $responseHeaders, $responseBody);
    }

    /**
     * @param array<string, list<string>> $headers
     * @return list<string>
     */
    private function flattenHeaders(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }
}
