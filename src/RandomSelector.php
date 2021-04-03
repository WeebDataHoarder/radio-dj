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
    private array $tagBias = [];
    private int $tagBiasSum = 0;
    private int $tagBiasMax = 0;
    private object $nr;


    public function __construct(LoopInterface $loop, Database $database, API $api) {
        $this->database = $database;
        $this->api = $api;

        $this->knownAlbums = new KnownValuesContainer(50);
        $this->knownArtists = new KnownValuesContainer(20);
        $this->knownTitles = new KnownValuesContainer(3000);
        $this->nr = (object) [];

        $this->api->getListeners()->then(function ($l) {
            $this->listeners = $l;
        });
        $this->api->getNowRandom()->then(function ($nr){
            $this->nr = $nr;
            $this->knownArtists->add($nr->artist);
            $this->knownAlbums->add($nr->album);
            $this->knownTitles->add($nr->title);
            $this->addSongTagBias($nr);
        });

        $this->database->getHistory(1000)->then(function ($songs){
            foreach ($songs as $song){
                $this->knownArtists->add($song->artist);
                $this->knownAlbums->add($song->album);
                $this->knownTitles->add($song->title);
                $this->addSongTagBias($song);
            }
        });

        $loop->addPeriodicTimer(10, [$this, "checkQueue"]);
    }

    public function getSongTagBias($song){
        $bias = 1;
        foreach ($song->tags as $t){
            if(!preg_match("#^(catalog|(ab|jps|red|bbt)[tgsa])-#iu", $t) and $t !== "op" and $t !== "ed" and $t !== "aotw"){
                $bias *= $this->getTagBias($t);
            }
        }
        return 100 * $bias;
    }

    public function getTagBias($tag){
        return ($this->tagBiasMax - $this->tagBias[$tag] ?? 0) / $this->tagBiasMax;
    }

    public function addSongTagBias($song){
        foreach ($song->tags as $t){
            if(!preg_match("#^(catalog|(ab|jps|red|bbt)[tgsa])-#iu", $t) and $t !== "op" and $t !== "ed" and $t !== "aotw"){
                $this->addTagBias($t);
            }
        }
    }

    public function addTagBias($tag){
        $this->tagBias[$tag] = ($this->tagBias[$tag] ?? 0) + 1;
        $this->tagBiasSum = array_sum($this->tagBias);
        $this->tagBiasMax = max($this->tagBias);
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
                $this->database->getSongsByTag("aotw", 100, Database::ORDER_BY_RANDOM),
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

                    if(count($song->favored_by) > 0 and count($song->favored_by) < 4){
                        $song->preferential = true;
                    }
                    return false;
                });
                $this->getBestFit($songs, $limit)->then($resolve);
            });
        });
    }

    public function getFromListeners($limit) : Promise {
        return new Promise(function (callable $resolve, callable $reject) use($limit){
            $promises = [];
            foreach ($this->listeners as $l){
                $promises[] = $this->database->getSongsByUserFavorite($l, 5, Database::ORDER_BY_RANDOM);
                $promises[] = new Promise(function ($resolve, $reject) use ($l) {
                    $this->database->getSongsByUserFavorite($l, 100, Database::ORDER_BY_RANDOM)->then(function ($songs) use ($resolve) {
                        $this->getRelated($songs, 20)->then($resolve);
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

                        if($s->play_count < 10){
                            $s->preferential = true;
                        }
                        if(count($s->favored_by) > 0 and count($s->favored_by) < 4){
                            $s->preferential = true;
                        }

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
                $songs = (isset($this->nr->id) and $initialSongs === null) ? array_merge($songs, [$this->nr]) : $songs;
                $ids = [];
                $artists = [];
                $albums = [];
                $tags = [];
                foreach ($songs as $song){
                    $ids[$song->id] = true;
                    if($this->knownAlbums->count($song->album) < 2){
                        $albums[] = $song->album;
                    }
                    if($this->knownArtists->count($song->artist) < 4){
                        $artists[] = $song->artist;
                    }
                    foreach ($song->tags as $t){
                        if(!preg_match("#^(catalog|(ab|jps|red|bbt)[tgsa])-#iu", $t) and $t !== "op" and $t !== "ed" and $t !== "aotw"){
                            $tags[] = $t;
                        }
                    }
                }

                foreach (array_unique($albums) as $album){
                    $promises[] = $this->database->getSongsByAlbum($album, 10, Database::ORDER_BY_SCORE);
                    $promises[] = $this->database->getSongsByAlbum($album, 50, Database::ORDER_BY_RANDOM);
                }
                foreach (array_unique($artists) as $artist){
                    $promises[] = $this->database->getSongsByArtist($artist, 10, Database::ORDER_BY_SCORE);
                    $promises[] = $this->database->getSongsByArtist($artist, 50, Database::ORDER_BY_RANDOM);
                }

                foreach (array_unique($tags) as $t){
                    $promises[] = $this->database->getSongsByTag($t, 15, Database::ORDER_BY_RANDOM);
                }

                \React\Promise\reduce($promises, function ($carry, $item){
                    return array_merge($carry, $item);
                }, [])->then(function ($data) use ($resolve, $limit, $ids){
                    $songs = $this->filterSongs($data, function ($song) use ($ids){
                        if(isset($ids[$song->id])){
                            return true;
                        }
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

        $this->recreateQueue()->then(function (){

        });
    }

    public function recreateQueue($desiredQueueLength = 32) : Promise{
        return new Promise(function ($resolve, $reject) use ($desiredQueueLength){
            $this->pool = $this->filterSongs($this->pool, function ($song){
                if($this->knownArtists->count($song->artist) > 0 and $this->knownAlbums->count($song->album) >= 2){
                    return true;
                }
                return false;
            });
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
                    $this->getBestFit($this->filterSongs($data, function ($song){
                        if($this->knownArtists->count($song->artist) > 0 and $this->knownAlbums->count($song->album) >= 2){
                            return true;
                        }
                        return false;
                    }), $desiredQueueLength - count($this->pool))->then(function ($songs) use ($resolve){
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
                    $score = isset($song->preferential) ? 20 : 1;
                    if(($nr->album ?? "") === $song->album){
                        $score += 50;
                    }
                    if(($nr->artist ?? "") === $song->artist){
                        $score += 50;
                    }
                    if(($np->album ?? "") === $song->album){
                        $score += 20;
                    }
                    if(($np->artist ?? "") === $song->artist){
                        $score += 20;
                    }

                    foreach ($song->tags as $t){
                        if(!preg_match("#^(catalog|(ab|jps|red|bbt)[tgsa])-#iu", $t) and $t !== "op" and $t !== "ed" and $t !== "aotw"){
                            if(isset($nr->tags) and in_array($t, $nr->tags, true)){
                                $score += 10;
                            }
                            if(isset($np->tags) and in_array($t, $np->tags, true)){
                                $score += 5;
                            }
                        }
                    }
                    foreach ($song->favored_by as $u){
                        if(in_array($u, $this->listeners, true)){
                            $score += 10;
                        }
                    }

                    foreach (["op", "ed", "aotw", "banger"] as $t){
                        if(in_array($t, $song->tags, true)){
                            $score += 5;
                        }
                    }

                    $score += min(40, count($song->favored_by) * 5);
                    $score += max(0, (10 - $song->play_count) * 4);

                    $score += $this->getSongTagBias($song);

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
                    $this->addSongTagBias($song);
                    $this->nr = $song;
                    $resolve($song);
                });
                return;
            }

            $this->getRelated(null, 500)->then(function ($songs) use($resolve, $reject){
                $p = array_merge($this->pool, $songs);

                $this->getBestFit($this->filterSongs($p, function ($song){
                    if($this->knownArtists->count($song->artist) > 0 and $this->knownAlbums->count($song->album) >= 2){
                        return true;
                    }
                    return false;
                }))->then(function ($songs) use ($resolve){
                    $this->database->getSongById($songs[0]->id)->then(function ($song) use ($resolve){
                        foreach ($this->pool as $k => $s){
                            if($song->id === $s->id){
                                unset($this->pool[$k]);
                            }
                        }
                        $this->knownArtists->add($song->artist);
                        $this->knownAlbums->add($song->album);
                        $this->knownTitles->add($song->title);
                        $this->addSongTagBias($song);
                        $this->nr = $song;
                        $resolve($song);
                    })->otherwise(function ($e) use ($resolve){
                        echo $e;
                        $this->database->getRandom()->then(function ($song) use($resolve){
                            $this->knownArtists->add($song->artist);
                            $this->knownAlbums->add($song->album);
                            $this->knownTitles->add($song->title);
                            $this->addSongTagBias($song);
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
                        $this->addSongTagBias($song);
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
            } else if($this->knownArtists->count($song->artist) >= 4){
                continue;
            } else if($this->knownAlbums->count($song->album) >= 2){
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
        return isset($this->values[strtolower($k)]);
    }

    public function count($k): int {
        return $this->values[strtolower($k)] ?? 0;
    }

    public function add($k){
        $k = strtolower($k);
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