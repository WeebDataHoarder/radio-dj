<?php

namespace Dj;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Promise;

class API{

    private Browser $client;
    private string $apiKey;

    public function __construct(LoopInterface $loop, string $baseUrl, string $apiKey){
        $this->client = (new Browser($loop))->withBase($baseUrl)->withTimeout(15);
        $this->apiKey = $apiKey;
    }

    public function getListeners() : Promise{
        return new Promise(function ($resolve, $reject) {
            $this->request("/api/listeners")->then(function ($data) use($resolve){
                $resolve(array_unique($data->named_listeners));
            })->otherwise($reject);
        });
    }

    public function getNowRandom() : Promise{
        return new Promise(function ($resolve, $reject) {
            $this->request("/api/nr")->then(function ($data) use($resolve){
                $resolve($data);
            })->otherwise($reject);
        });
    }

    public function getQueue() : Promise{
        return new Promise(function ($resolve, $reject) {
            $this->request("/api/queue")->then(function ($data) use($resolve){
                $resolve($data);
            })->otherwise($reject);
        });
    }

    private function request(string $endpoint, string $method = "GET", string $postData = "", array $headers = []) : Promise{
        return new Promise(function ($resolve, $reject) use($endpoint, $method, $postData, $headers){
            $this->client->request($method, $endpoint, array_merge([
                "Authorization" => $this->apiKey
            ], $headers), $postData)->then(function (ResponseInterface $response) use($resolve){
                $ct = $response->getHeader("content-type");
                $ct = is_array($ct) ? $ct[0] ?? "" : $ct;
                if(strpos($ct, "/json") !== false){
                    $resolve(json_decode($response->getBody()));
                    return;
                }
                $resolve($response->getBody());
            })->otherwise($reject);
        });
    }
}