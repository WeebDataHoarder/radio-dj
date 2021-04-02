<?php

namespace Dj;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;

class RandomSelector {
    private Database $database;
    private API $api;


    private KnownValuesContainer $knownArtists;
    private KnownValuesContainer $knownAlbums;
    private KnownValuesContainer $knownTitles;

    private array $pool = [];
    private array $listeners = [];


    public function __construct(LoopInterface $loop, Database $database, API $api) {
        $this->database = $database;
        $this->api = $api;

        $this->knownAlbums = new KnownValuesContainer(100);
        $this->knownArtists = new KnownValuesContainer(300);
        $this->knownTitles = new KnownValuesContainer(3000);

        $loop->addPeriodicTimer(30, [$this, "checkQueue"]);
    }

    public function getRandomOpEd($limit = 5) : Promise {
        return new Promise(function (callable $resolve, callable $reject) use($limit){
            \React\Promise\reduce([
                $this->database->getSongsByTag("op", 50, Database::ORDER_BY_RANDOM),
                $this->database->getSongsByTag("ed", 50, Database::ORDER_BY_RANDOM),
            ], function ($carry, $item){
                return array_merge($carry, $item);
            }, [])->then(function ($data) use ($resolve, $limit){
                $songs = $this->filterSongs($data);
                $selected = [];
                for($i = 0; $i < $limit; ++$i){
                    if(count($songs) > 0){
                        shuffle($songs);
                        $selected[] = array_pop($songs);
                    }
                }
                $resolve($selected);
            });
        });
    }

    public function getGems($limit = 5) : Promise {
        return new Promise(function (callable $resolve, callable $reject) use($limit){
            \React\Promise\reduce([
                $this->database->getFavoriteCountSongs(1, 3, 100, Database::ORDER_BY_RANDOM),
                $this->database->getSongsByTag("aotw", 50, Database::ORDER_BY_RANDOM),
            ], function ($carry, $item){
                return array_merge($carry, $item);
            }, [])->then(function ($data) use ($resolve, $limit){
                $songs = $this->filterSongs($data, function ($song){
                    foreach ($song->favored_by as $u){
                        if(in_array($u, $this->listeners, true)){
                            return true;
                        }
                    }
                    if($song->play_count > 10){
                        return true;
                    }

                    return false;
                });
                $selected = [];
                for($i = 0; $i < $limit; ++$i){
                    if(count($songs) > 0){
                        shuffle($songs);
                        $selected[] = array_pop($songs);
                    }
                }
                $resolve($selected);
            });
        });
    }

    public function getFromListeners($limit = 5) : Promise {
        return new Promise(function (callable $resolve, callable $reject) use($limit){
            $promises = [];
            foreach ($this->listeners as $l){
                $promises[] = $this->database->getSongsByUserFavorite($l, 100, Database::ORDER_BY_RANDOM);
            }

            \React\Promise\reduce($promises, function ($carry, $item){
                return array_merge($carry, $item);
            }, [])->then(function ($data) use ($resolve, $limit){
                $songs = $this->filterSongs($data);
                $selected = [];
                for($i = 0; $i < $limit; ++$i){
                    if(count($songs) > 0){
                        shuffle($songs);
                        $selected[] = array_pop($songs);
                    }
                }
                $resolve($selected);
            });
        });
    }

    public function getRelated($limit = 5) : Promise {
        return new Promise(function (callable $resolve, callable $reject) use($limit){
            $this->database->getHistory(5)->then(function ($songs) use ($resolve, $limit) {
                $promises = [];
                foreach ($songs as $song){
                    if($this->knownAlbums->count($song->album) < 2){
                        $promises[] = $this->database->getSongsByAlbum($song->album, 50);
                        $promises[] = $this->database->getSongsByAlbum($song->album, 10, Database::ORDER_BY_RANDOM);
                    }
                    if($this->knownArtists->count($song->artist) < 2){
                        $promises[] = $this->database->getSongsByArtist($song->artist, 50);
                        $promises[] = $this->database->getSongsByArtist($song->artist, 10, Database::ORDER_BY_RANDOM);
                    }

                }

                \React\Promise\reduce($promises, function ($carry, $item){
                    return array_merge($carry, $item);
                }, [])->then(function ($data) use ($resolve, $limit){
                    $songs = $this->filterSongs($data);
                    $selected = [];
                    for($i = 0; $i < $limit; ++$i){
                        if(count($songs) > 0){
                            shuffle($songs);
                            $selected[] = array_pop($songs);
                        }
                    }
                    $resolve($selected);
                });
            });
        });
    }

    public function checkQueue(){
        $this->api->getListeners()->then(function ($l) {
            $this->listeners = $l;
        });

        $this->pool = $this->filterSongs($this->pool);

        $this->recreateQueue()->then(function (){

        });
    }

    public function recreateQueue($desiredQueueLength = 64) : Promise{
        return new Promise(function ($resolve, $reject) use ($desiredQueueLength){
            if(count($this->pool) < $desiredQueueLength){
                $promises = [];
                if(count($this->listeners) > 0){
                    $promises[] = $this->getFromListeners(30);
                }
                $promises[] = $this->getGems(20);
                $promises[] = $this->getRandomOpEd(10);
                $promises[] = $this->getRelated(15);
                $promises[] = new Promise(function ($resolve, $reject){
                    $this->database->getRandom()->then(function ($r) use ($resolve){
                        $resolve([$r]);
                    })->otherwise($reject);
                });

                \React\Promise\reduce($promises, function ($carry, $item){
                    return array_merge($carry, $item);
                }, [])->then(function ($data) use ($resolve, $desiredQueueLength){
                    $songs = $this->filterSongs($data);
                    for($i = count($this->pool); $i < $desiredQueueLength; ++$i){
                        if(count($songs) > 0){
                            shuffle($songs);
                            $this->pool[] = array_pop($songs);
                        }
                    }
                    $resolve();
                });
            }else{
                $resolve();
            }
        });
    }

    public function getQueue(): array {
        return $this->pool;
    }

    public function popQueue(): Promise {
        return new Promise(function (callable $resolve, callable $reject){
            if(count($this->pool) === 0){
                //Fallback
                $this->database->getRandom()->then(function ($song) use($resolve){
                    $this->knownArtists->add($song->artist);
                    $this->knownAlbums->add($song->album);
                    $this->knownTitles->add($song->title);
                    $resolve($song);
                });
                return;
            }

            $this->database->getNowPlaying()->then(function ($np) use ($resolve){
                $bestFit = [0, null];
                shuffle($this->pool);
                foreach (array_slice($this->pool, 0, ceil(count($this->pool) / 2)) as $song){
                    $score = 1;
                    if($np->album === $song->album){
                        $score += 100;
                    }
                    if($np->artist === $song->artist){
                        $score += 100;
                    }
                    foreach ($np->favored_by as $u){
                        if(in_array($u, $this->listeners, true)){
                            $score += 10;
                        }
                    }

                    $score += count($song->favored_by);

                    if($score > $bestFit[0]){
                        $bestFit = [$score, $song];
                    }
                }
                $song = $bestFit[1];

                $this->knownArtists->add($song->artist);
                $this->knownAlbums->add($song->album);
                $this->knownTitles->add($song->title);
                $resolve($song);
            });


        });

    }

    public function filterSongs(array $entries, callable $filter = null): array {
        $newData = [];
        foreach ($entries as $song){
            if($this->knownTitles->count($song->title) >= 1){
                continue;
            } else if($this->knownArtists->count($song->artist) >= 5){
                continue;
            } else if($this->knownAlbums->count($song->album) >= 5){
                continue;
            } else if(is_callable($filter) and $filter($song)){
                continue;
            }

            $newData[] = $song;
        }
        return $newData;
    }
}

class KnownValuesContainer {
    private array $values = [];
    private int $maxSize;

    public function __construct(int $maxSize){
        $this->maxSize = $maxSize;
    }

    public function exists($k): bool {
        return isset($this->values[$k]);
    }

    public function count($k): int {
        return $this->values[$k] ?? 0;
    }

    public function add($k){
        $value = 1;
        if($this->exists($k)){
            $value = $this->values[$k] + 1;
            unset($this->values[$k]);
        } else if(count($this->values) >= $this->maxSize) {
            array_shift($this->values);
        }

        $this->values[$k] = $value;
    }
}