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
    private $nr;


    public function __construct(LoopInterface $loop, Database $database, API $api) {
        $this->database = $database;
        $this->api = $api;

        $this->knownAlbums = new KnownValuesContainer(100);
        $this->knownArtists = new KnownValuesContainer(300);
        $this->knownTitles = new KnownValuesContainer(3000);
        $this->nr = (object) [];

        $this->database->getHistory(500)->then(function ($songs){
            foreach ($songs as $song){
                $this->knownArtists->add($song->artist);
                $this->knownAlbums->add($song->album);
                $this->knownTitles->add($song->title);
            }
        });

        $loop->addPeriodicTimer(5, [$this, "checkQueue"]);
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
                        $s = array_pop($songs);
                        $s->preferential = true;
                        $selected[] = $s;
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
                        $s = array_pop($songs);
                        $s->preferential = true;
                        $selected[] = $s;
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
                $promises[] = new Promise(function ($resolve, $reject) use ($l) {
                    $this->database->getSongsByUserFavorite($l, 10, Database::ORDER_BY_RANDOM)->then(function ($songs) use ($resolve) {
                        $this->getRelated($songs, 100)->then($resolve);
                    });
                });
            }

            \React\Promise\reduce($promises, function ($carry, $item){
                return array_merge($carry, $item);
            }, [])->then(function ($data) use ($resolve, $limit){
                $songs = $this->filterSongs($data);
                $selected = [];
                for($i = 0; $i < $limit; ++$i){
                    if(count($songs) > 0){
                        shuffle($songs);
                        $s = array_pop($songs);
                        $s->preferential = true;
                        $selected[] = $s;
                    }
                }
                $resolve($selected);
            });
        });
    }

    public function getRelated($initialSongs = null, $limit = 5) : Promise {
        return new Promise(function (callable $resolve, callable $reject) use($initialSongs, $limit){
            ($initialSongs === null ? $this->database->getHistory(5) : \React\Promise\resolve($initialSongs))->then(function ($songs) use ($initialSongs, $resolve, $limit) {
                $promises = [];
                $songs = (isset($this->nr->hash) and $initialSongs === null) ? array_merge($songs, [$this->nr]) : $songs;
                foreach ($songs as $song){
                    if($this->knownAlbums->count($song->album) < 5){
                        $promises[] = $this->database->getSongsByAlbum($song->album, 50);
                        $promises[] = $this->database->getSongsByAlbum($song->album, 10, Database::ORDER_BY_RANDOM);
                    }
                    if($this->knownArtists->count($song->artist) < 5){
                        $promises[] = $this->database->getSongsByArtist($song->artist, 50);
                        $promises[] = $this->database->getSongsByArtist($song->artist, 10, Database::ORDER_BY_RANDOM);
                    }

                }

                \React\Promise\reduce($promises, function ($carry, $item){
                    return array_merge($carry, $item);
                }, [])->then(function ($data) use ($resolve, $limit){
                    $songs = $this->filterSongs($data, function ($song){
                        if($this->knownAlbums->count($song->album) >= 2){
                            return true;
                        }
                        return false;
                    });
                    if(count($songs) > $limit){
                        $selected = [];
                        for($i = 0; $i < $limit; ++$i){
                            if(count($songs) > 0){
                                shuffle($songs);
                                $selected[] = array_pop($songs);
                            }
                        }
                    }else{
                        $selected = $songs;
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
        $this->api->getNowRandom()->then(function ($nr){
            $this->nr = $nr;
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
                    $promises[] = $this->getFromListeners(200);
                }
                $promises[] = $this->getGems(200);
                $promises[] = $this->getRandomOpEd(200);
                $promises[] = $this->getRelated(null, 15);
                $promises[] = (new Promise(function ($resolve, $reject){
                    $this->database->getRandom()->then(function ($r) use ($resolve){
                        $resolve([$r]);
                    })->otherwise($reject);
                }));

                \React\Promise\reduce($promises, function ($carry, $item){
                    return array_merge($carry, $item);
                }, [])->then(function ($data) use ($promises, $resolve, $desiredQueueLength){
                    $this->getBestFit($this->filterSongs($data), $desiredQueueLength - count($this->pool))->then(function ($songs) use ($resolve){
                        $this->pool = array_merge($this->pool, $songs);
                        $resolve();
                    });
                });
            }else{
                $resolve();
            }
        });
    }

    public function getQueue(): array {
        return $this->pool;
    }

    public function getBestFit($songs, $limit = 1){
        return new Promise(function (callable $resolve, callable $reject) use ($songs, $limit){
            $this->database->getNowPlaying()->then(function ($np) use ($songs, $limit, $resolve, $reject){
                $nr = $this->nr;
                foreach ($songs as $song){
                    $score = isset($song->preferential) ? 50 : 1;
                    if(($nr->album ?? "") === $song->album){
                        $score += 100;
                    }
                    if(($nr->artist ?? "") === $song->artist){
                        $score += 100;
                    }
                    if(($np->album ?? "") === $song->album){
                        $score += 20;
                    }
                    if(($np->artist ?? "") === $song->artist){
                        $score += 20;
                    }
                    foreach ($song->favored_by as $u){
                        if(in_array($u, $this->listeners, true)){
                            $score += 50;
                        }
                    }

                    $score += count($song->favored_by);
                    $score += max(0, (10 - $song->play_count) * 4);

                    $song->score = $score;
                }

                usort($songs, function (\stdClass $a, \stdClass $b){
                    if($a->score === $b->score){
                        return 0;
                    }
                    return ($a->score > $b->score) ? -1 : 1;
                });

                $resolve(array_slice($songs, 0, min(count($songs), $limit)));
            })->otherwise($reject);
        });
    }

    public function popQueue(): Promise {
        return new Promise(function (callable $resolve, callable $reject){
            if(count($this->pool) === 0){
                //Fallback
                $this->database->getRandom()->then(function ($song) use($resolve){
                    $this->knownArtists->add($song->artist);
                    $this->knownAlbums->add($song->album);
                    $this->knownTitles->add($song->title);
                    $this->nr = $song;
                    $resolve($song);
                });
                return;
            }

            $this->getRelated(null, 500)->then(function ($songs) use($resolve, $reject){
                $p = array_merge($this->pool, $songs);

                $this->getBestFit($p)->then(function ($songs) use ($resolve){
                    $this->database->getSongById($songs[0]->id)->then(function ($song) use ($resolve){
                        foreach ($this->pool as $k => $s){
                            if($song->id === $s->id){
                                unset($this->pool[$k]);
                            }
                        }
                        $this->knownArtists->add($song->artist);
                        $this->knownAlbums->add($song->album);
                        $this->knownTitles->add($song->title);
                        $this->nr = $song;
                        $resolve($song);
                    })->otherwise(function ($e) use ($resolve){
                        echo $e;
                        $this->database->getRandom()->then(function ($song) use($resolve){
                            $this->knownArtists->add($song->artist);
                            $this->knownAlbums->add($song->album);
                            $this->knownTitles->add($song->title);
                            $this->nr = $song;
                            $resolve($song);
                        });
                    });
                })->otherwise(function ($e) use ($resolve){
                    echo $e;
                    $this->database->getRandom()->then(function ($song) use($resolve){
                        $this->knownArtists->add($song->artist);
                        $this->knownAlbums->add($song->album);
                        $this->knownTitles->add($song->title);
                        $this->nr = $song;
                        $resolve($song);
                    });
                });
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