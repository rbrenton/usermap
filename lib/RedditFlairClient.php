<?php

class RedditFlairClient
{
    private $ch = null;
    private $accessToken = null;
    private $accessExpiresAt = 0;
    private $nextUrl;
    private $lastAfter = null;

    private $username;
    private $password;
    private $clientId;
    private $clientSecret;
    private $flairUrl;
    private $tokenUrl;
    private $userAgent;
    private $rateLimiter;

    public function __construct(
        $username,
        $password,
        $clientId,
        $clientSecret,
        $flairUrl,
        $tokenUrl,
        $userAgent,
        RateLimiter $rateLimiter
    ) {
        $this->username    = $username;
        $this->password    = $password;
        $this->clientId    = $clientId;
        $this->clientSecret = $clientSecret;
        $this->flairUrl    = $flairUrl;
        $this->tokenUrl    = $tokenUrl;
        $this->userAgent   = $userAgent;
        $this->rateLimiter = $rateLimiter;
        $this->nextUrl     = $flairUrl;
    }

    /**
     * Fetch the next page of flair data.
     * Returns an associative array of username => flair_text, or null when exhausted.
     */
    public function fetchNextPage()
    {
        if ($this->nextUrl === null) {
            return null;
        }

        $this->ensureAuthenticated();

        $result = $this->get($this->nextUrl);
        if ($result === null) {
            return null;
        }

        $obj = json_decode($result);
        if (!is_object($obj)) {
            throw new Exception("Failed to parse flair response as JSON.");
        }

        if (property_exists($obj, 'error')) {
            printf("RedditFlairClient: error=%s message=%s\n",
                $obj->error,
                property_exists($obj, 'message') ? $obj->message : ''
            );
            return null;
        }

        $after = property_exists($obj, 'next') ? $obj->next : null;
        if ($after !== null && $after !== $this->lastAfter) {
            $this->nextUrl  = $this->flairUrl . '&after=' . $after;
            $this->lastAfter = $after;
        } else {
            $this->nextUrl = null;
        }

        $flair = array();
        foreach ($obj->users as $user) {
            $flair[$user->user] = $user->flair_text;
        }
        return $flair;
    }

    private function ensureAuthenticated()
    {
        if ($this->ch !== null && $this->accessToken !== null && $this->accessExpiresAt > time()) {
            return;
        }

        if ($this->ch === null) {
            $this->ch = curl_init();
            curl_setopt($this->ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
        }

        $fields = array(
            'grant_type' => 'password',
            'username'   => $this->username,
            'password'   => $this->password,
        );

        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($this->ch, CURLOPT_POST, 1);
        curl_setopt($this->ch, CURLOPT_URL, $this->tokenUrl);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($this->ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array());

        printf("RedditFlairClient: fetching token url=%s\n", $this->tokenUrl);
        $this->rateLimiter->wait('redditoauth');
        $result = curl_exec($this->ch);

        if (!$result) {
            throw new Exception("Token request failed: " . curl_error($this->ch));
        }

        printf("RedditFlairClient: token result=%s\n", self::maskSecrets($result));
        $obj = json_decode($result);

        if (!is_object($obj)) {
            throw new Exception("Failed to parse token response as JSON.");
        }

        if (property_exists($obj, 'error')) {
            throw new Exception(sprintf(
                "OAuth error: %s %s",
                $obj->error,
                property_exists($obj, 'error_description') ? $obj->error_description : ''
            ));
        }

        if (!property_exists($obj, 'access_token')) {
            throw new Exception("Token response missing access_token.");
        }
        if (!property_exists($obj, 'expires_in')) {
            throw new Exception("Token response missing expires_in.");
        }

        $this->accessToken      = $obj->access_token;
        $this->accessExpiresAt  = time() + (int)floor($obj->expires_in * 0.9);

        // Reset cURL to GET defaults for subsequent flair requests.
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($this->ch, CURLOPT_POST, 0);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, null);
        curl_setopt($this->ch, CURLOPT_USERPWD, '');
    }

    private function get($url)
    {
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: bearer ' . $this->accessToken,
        ));
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 3);

        printf("RedditFlairClient: fetching url=%s\n", $url);
        $this->rateLimiter->wait('redditflair');
        $result = curl_exec($this->ch);

        if (!$result) {
            printf("RedditFlairClient: fetch failed url=%s error=%s\n", $url, curl_error($this->ch));
            $this->nextUrl = null;
            return null;
        }

        printf("RedditFlairClient: result=%s\n", $result);
        return $result;
    }

    private static function maskSecrets($str)
    {
        $str = preg_replace('/"access_token": "[^"]+"/', '"access_token": "**REDACTED**"', $str);
        $str = preg_replace('/eyJhbGciOi[A-Za-z0-9._-]+/', '**REDACTED**', $str);
        return $str;
    }
}
