<?php

namespace Dj;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\Promise;

class RequestHandler{
    private RandomSelector $selector;
    public function __construct(RandomSelector $selector) {
        $this->selector = $selector;
    }

    public function handleRequest(ServerRequestInterface $request): Promise {
        return new Promise(function (callable $resolve, callable $reject) use ($request) {
            $path = $request->getUri()->getPath();

            if(preg_match("#^/random$#", $path) > 0){
                $this->selector->popQueue()->then(function ($song) use ($resolve) {
                    $resolve($this->_jsonResponse($song));
                })->otherwise($reject);
            } else if(preg_match("#^/queue$#", $path) > 0){
                $this->selector->recreateQueue()->then(function () use ($resolve) {
                    $resolve($this->_jsonResponse($this->selector->getQueue()));
                })->otherwise($reject);
            }else{
                $resolve(new Response(404));
            }
        });
    }

    private function _jsonResponse($data): Response {
        return new Response(
            200,
            array(
                'Content-Type' => 'application/json; charset=utf-8'
            ),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

}