<?php

namespace iRacingPHP;

use iRacingPHP\Exceptions\RequestRateLimitedException;
use iRacingPHP\Exceptions\SiteMaintenanceException;
use iRacingPHP\Exceptions\RequestFailedException;
use iRacingPHP\Exceptions\AuthorizationFailedException;
use iRacingPHP\Models\RateLimits;
use iRacingPHP\Exceptions\DataRequestFailedException;

class Api
{
    private $tokenProvider; // callable: fn(): ?string
    private \GuzzleHttp\Client $guzzle;

    public RateLimits $rateLimits;

    function __construct(callable $tokenProvider)
    {
        $this->tokenProvider = $tokenProvider;
        $this->guzzle = new \GuzzleHttp\Client();
        $this->rateLimits = new RateLimits();
    }

    /**
     * Retrieved cached data via the URL provided by the API,
     * and any associated chunks.
     *
     * @param string $url
     * @return mixed
     * @throws DataRequestFailedException
     */
    private function retrieveData(string $url)
    {
        try
        {
            $response = $this->guzzle->request('GET', $url);
            $responseBody = json_decode($response->getBody());

            if(isset($responseBody->chunk_info))
            {
                $responseBody->data = $this->retrieveChunks($responseBody->chunk_info);
                unset($responseBody->chunk_info);
            }

            return $responseBody;
        }
        catch(\GuzzleHttp\Exception\BadResponseException $e)
        {
            throw new DataRequestFailedException($e->getMessage(), 0, $e);
        }
        return null;
    }

    /**
     * Retrieves cached data chunks from the URL provided by the API.
     *
     * @param mixed $body
     * @return mixed
     * @throws DataRequestFailedException
     */
    private function retrieveChunks(mixed $chunks)
    {
        $result = [];
        try
        {
            $baseUrl = $chunks->base_download_url;
            foreach($chunks->chunk_file_names as $fileName)
            {
                $response = $this->guzzle->request('GET', $baseUrl . $fileName);
                $result[] = json_decode($response->getBody());
            }

            return $result;
        }
        catch(\GuzzleHttp\Exception\BadResponseException $e)
        {
            throw new DataRequestFailedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Public request method, used by the Data classes.
     * Retrieves the cached data link or chunk data from the API endpoint, then the data itself.
     *
     * @param string $endpoint 
     * @param array $data Optional parameters to be passed with the request
     * @return mixed Requested data
     */
    public function request(string $endpoint, array $data = [])
    {
        $url = LibConstants::APIURL . $endpoint;
        try
        {
            $token = is_callable($this->tokenProvider)
                ? call_user_func($this->tokenProvider)
                : null;

            if (!$token) {
                throw new AuthorizationFailedException('Unauthorized: missing access token', 401);
            }

            $response = $this->guzzle->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'query' => $data
            ]);

            $this->setRateLimits($response);

            $responseBody = json_decode($response->getBody());
            if(isset($responseBody->link))
            {
                return $this->retrieveData($responseBody->link);
            }
            if(isset($responseBody->data->chunk_info))
            {
                return $this->retrieveChunks($responseBody->data->chunk_info);
            }
        }
        catch(\GuzzleHttp\Exception\BadResponseException $e)
        {
            $response = $e->getResponse();
            $this->setRateLimits($response);
            if($this->shouldRetryRequest($response, $e->getMessage(), $e))
            {
                return $this->request($endpoint, $data);
            }
        }
        return null;
    }

    /**
     * Updates the rate limit values.
     *
     * @param \GuzzleHttp\Psr7\Response $response API request response object.
     * @return void
     */
    private function setRateLimits(\GuzzleHttp\Psr7\Response $response)
    {
        $this->rateLimits->limit = (int)$response->getHeaderLine('x-ratelimit-limit');
        $this->rateLimits->remaining = (int)$response->getHeaderLine('x-ratelimit-remaining');
        $this->rateLimits->reset = (int)$response->getHeaderLine('x-ratelimit-reset');
    }

    /**
     * Checks response code, attempts authentication if unauthorized, throws specific exceptions otherwise.
     *
     * @param \GuzzleHttp\Psr7\Response $response Response returned by the request
     * @param string $message Response message to be injected into Exceptions
     * @return boolean True if another attempt to make the request should be made (after authentication)
     * @throws RequestRateLimitedException
     * @throws SiteMaintenanceException
     * @throws RequestFailedException|AuthenticationFailedException
     */
    private function shouldRetryRequest(\GuzzleHttp\Psr7\Response $response, string $message, \Exception $oldEx)
    {
        switch($response->getStatusCode())
        {
            case 401:
                throw new AuthorizationFailedException(
                    'Unauthorized',
                    401,
                    (string)$response->getBody(),
                    $response->getHeaders(),
                    $oldEx
                );
            case 429:
                throw new RequestRateLimitedException('Rate limit exceeded', 0, $oldEx);
            case 503:
                throw new SiteMaintenanceException('Site maintenance', 0, $oldEx);
        }

        throw new RequestFailedException($message, 0, $oldEx);
    }

}