<?php

namespace Dj;

use PgAsync\Client;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;

class Database{
    private const SONG_SQL = <<<'SQL'
SELECT
songs.id AS id,
songs.hash AS hash,
songs.title AS title,
(SELECT artists.name FROM artists WHERE songs.artist = artists.id LIMIT 1) AS artist,
(SELECT albums.name FROM albums WHERE songs.album = albums.id LIMIT 1) AS album,    
songs.path AS path,
songs.duration AS duration,
songs.favorite_count AS favorite_count,
songs.play_count AS play_count,
songs.cover AS cover,
array_to_json(ARRAY(SELECT jsonb_object_keys(songs.lyrics))) AS lyrics,
songs.status AS status,
array_to_json(ARRAY(SELECT tags.name FROM tags JOIN taggings ON (taggings.tag = tags.id) WHERE taggings.song = songs.id)) AS tags,
array_to_json(ARRAY(SELECT users.name FROM users JOIN favorites ON (favorites.user_id = users.id) WHERE favorites.song = songs.id)) AS favored_by
FROM songs
{WHERE_REPLACEMENT}
SQL;


    private const SIMPLE_SONG_SQL = <<<'SQL'
SELECT
songs.id AS id,
songs.hash AS hash,
songs.title AS title,
(SELECT artists.name FROM artists WHERE songs.artist = artists.id LIMIT 1) AS artist,
(SELECT albums.name FROM albums WHERE songs.album = albums.id LIMIT 1) AS album,    
songs.duration AS duration,
songs.play_count AS play_count,
array_to_json(ARRAY(SELECT tags.name FROM tags JOIN taggings ON (taggings.tag = tags.id) WHERE taggings.song = songs.id)) AS tags,
array_to_json(ARRAY(SELECT users.name FROM users JOIN favorites ON (favorites.user_id = users.id) WHERE favorites.song = songs.id)) AS favored_by
FROM songs
{WHERE_REPLACEMENT}
SQL;
    public const ORDER_BY_SCORE = 'ORDER BY (favorite_count * 5 + play_count + (CASE WHEN path ILIKE \'%%.flac\' THEN 5 ELSE 0 END)) DESC, path ASC';
    public const ORDER_BY_RANDOM = 'ORDER BY random()';
    private Client $client;

    public function __construct(LoopInterface $loop, $host, $port, $user, $password, $db){
        $this->client = new Client([
            "host" => $host,
            "port" => $port,
            "user" => $user,
            "password" => $password,
            "database" => $db
        ], $loop);
    }

    private function _decodeSongEntry(&$song){
        foreach($song as $k=>&$v){
            if($k === "tags" or $k === "favored_by" or $k === "song_metadata" or $k === "lyrics"){
                $v = json_decode($v, true);
            }
        }

        $song = (object) $song;
    }

    public function getRandom(): Promise {
        return new Promise(function (callable $resolve, callable $reject) {
            $sql = self::SONG_SQL;

            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active'", $sql);

            $this->client->executeStatement($sql . " " . self::ORDER_BY_RANDOM . " LIMIT 1;", [])->subscribe(function ($row) use ($resolve){
                $this->_decodeSongEntry($row);
                $resolve($row);
            }, function ($e) use ($reject) {
                $reject($e);
            });
        });
    }

    public function getSongById(int $id): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($id) {
            $sql = self::SONG_SQL;

            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.id = \$1", $sql);

            $this->client->executeStatement($sql . " LIMIT 1;", [$id])->subscribe(function ($row) use ($resolve){
                $this->_decodeSongEntry($row);
                $resolve($row);
            }, function ($e) use ($reject) {
                $reject($e);
            });
        });
    }

    public function getSongByHash(string $hash): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($hash) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.hash = \$1", $sql);

            $this->client->executeStatement($sql . " LIMIT 1;", [strtolower($hash)])->subscribe(function ($row) use ($resolve){
                $this->_decodeSongEntry($row);
                $resolve($row);
            }, function ($e) use ($reject) {
                $reject($e);
            });
        });
    }

    public function getSongsByTag(string $string, int $limit = 0, $orderBy = self::ORDER_BY_SCORE): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($string, $limit, $orderBy) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.id IN(SELECT song FROM taggings WHERE taggings.tag = (SELECT id FROM tags WHERE tags.name = \$1))", $sql);

            $result = [];
            $this->client->executeStatement($sql . " " . $orderBy . ($limit > 0 ? " LIMIT " . $limit . ";" : ""), [$string])->subscribe(function ($row) use (&$result){
                $this->_decodeSongEntry($row);
                $result[] = $row;
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$result){
                $resolve($result);
            });
        });
    }

    public function getSongsByUserFavorite(string $string, int $limit = 0, $orderBy = self::ORDER_BY_SCORE): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($string, $limit, $orderBy) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.id IN(SELECT song FROM favorites WHERE favorites.user_id = (SELECT id FROM users WHERE users.name = \$1))", $sql);

            $result = [];
            $this->client->executeStatement($sql . " " . $orderBy . ($limit > 0 ? " LIMIT " . $limit . ";" : ""), [$string])->subscribe(function ($row) use (&$result){
                $this->_decodeSongEntry($row);
                $result[] = $row;
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$result){
                $resolve($result);
            });
        });
    }

    public function getFavoriteCountSongs(int $minCount, int $maxCount, int $limit = 0, $orderBy = self::ORDER_BY_SCORE): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($minCount, $maxCount, $limit, $orderBy) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.favorite_count > \$1 AND songs.favorite_count <= \$2", $sql);

            $result = [];
            $this->client->executeStatement($sql . " " . $orderBy . ($limit > 0 ? " LIMIT " . $limit . ";" : ""), [$minCount, $maxCount])->subscribe(function ($row) use (&$result){
                $this->_decodeSongEntry($row);
                $result[] = $row;
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$result){
                $resolve($result);
            });
        });
    }

    public function getSongsByNotUserFavorite(string $string, int $limit = 0, $orderBy = self::ORDER_BY_SCORE): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($string, $limit, $orderBy) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.favorite_count > 0 AND NOT songs.id IN(SELECT song FROM favorites WHERE favorites.user_id = (SELECT id FROM users WHERE users.name = \$1))", $sql);

            $result = [];
            $this->client->executeStatement($sql . " " . $orderBy . ($limit > 0 ? " LIMIT " . $limit . ";" : ""), [$string])->subscribe(function ($row) use (&$result){
                $this->_decodeSongEntry($row);
                $result[] = $row;
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$result){
                $resolve($result);
            });
        });
    }

    public function getSongsByAlbum(string $string, int $limit = 0, $orderBy = self::ORDER_BY_SCORE): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($string, $limit, $orderBy) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.album IN(SELECT id FROM albums WHERE name ILIKE \$1)", $sql);

            $result = [];
            $this->client->executeStatement($sql . " " . $orderBy . ($limit > 0 ? " LIMIT " . $limit . ";" : ""), [$string])->subscribe(function ($row) use (&$result){
                $this->_decodeSongEntry($row);
                $result[] = $row;
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$result){
                $resolve($result);
            });
        });
    }

    public function getSongsByArtist(string $string, int $limit = 0, $orderBy = self::ORDER_BY_SCORE): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($string, $limit, $orderBy) {
            $sql = self::SONG_SQL;
            $sql = str_replace("{WHERE_REPLACEMENT}", "WHERE songs.status = 'active' AND songs.artist IN(SELECT id FROM artists WHERE name ILIKE \$1)", $sql);

            $result = [];
            $this->client->executeStatement($sql . " " . $orderBy . ($limit > 0 ? " LIMIT " . $limit . ";" : ""), [$string])->subscribe(function ($row) use (&$result){
                $this->_decodeSongEntry($row);
                $result[] = $row;
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$result){
                $resolve($result);
            });
        });
    }

    public function getHistory($limit = 100): Promise {
        return new Promise(function (callable $resolve, callable $reject) use($limit) {
            $promises = [];
            $this->client->executeStatement("SELECT EXTRACT(EPOCH FROM play_time) as play_time, song, source FROM history ORDER BY play_time DESC LIMIT \$1;", [$limit])->subscribe(function ($row) use (&$promises){
                $promises[] = $this->getSongById($row["song"]);
            }, function ($e) use ($reject) {
                $reject($e);
            }, function () use ($resolve, &$promises){
                \React\Promise\all($promises)->then($resolve);
            });
        });
    }

    public function getNowPlaying(): Promise {
        return new Promise(function (callable $resolve, callable $reject){
            $this->getHistory(1)->then(function ($data) use ($resolve){
                $resolve($data[0] ?? null);
            })->otherwise($reject);
        });
    }
}