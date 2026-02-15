<?php

namespace App\Services\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;

class LocationInfoTable
{
    public function __construct(
        private DynamoDbClient $ddb,
    ) {}

    private function table(): string
    {
        return config('dynamodb.tables.location');
    }

    /**
     * 履歴 + 最新(LATEST)を両方保存（推奨）
     */
    public function putLocation(array $data): void
    {
        $modelNumber = (string)($data['model_number'] ?? '');
        $timeId      = (string)($data['time_id'] ?? '');

        if ($modelNumber === '' || $timeId === '') {
            throw new \InvalidArgumentException('model_number and time_id are required.');
        }

        $item = [
            'model_number' => ['S' => $modelNumber],
            'time_id'      => ['S' => $timeId],

            'lat'        => ['S' => (string)($data['lat'] ?? '')],
            'lng'        => ['S' => (string)($data['lng'] ?? '')],
            'alt'        => ['S' => (string)($data['alt'] ?? '')],
            'stl'        => ['S' => (string)($data['stl'] ?? '')],
            'vol'        => ['S' => (string)($data['vol'] ?? '')],
            'imsi'       => ['S' => (string)($data['imsi'] ?? '')],
            'imei'       => ['S' => (string)($data['imei'] ?? '')],
            'type'       => ['S' => (string)($data['type'] ?? '')],
            'loc_data'   => ['S' => (string)($data['loc_data'] ?? '')],
            'ns'         => ['S' => (string)($data['ns'] ?? '')],
            'ew'         => ['S' => (string)($data['ew'] ?? '')],
            'major_axis' => ['S' => (string)($data['major_axis'] ?? '')],
            'minor_axis' => ['S' => (string)($data['minor_axis'] ?? '')],
            'bat'        => ['S' => (string)($data['bat'] ?? '')],
        ];

        // 履歴
        $this->ddb->putItem([
            'TableName' => $this->table(),
            'Item' => $item,
        ]);

        // 最新（LATEST）
        $latest = $item;
        $latest['time_id'] = ['S' => 'LATEST'];

        $this->ddb->putItem([
            'TableName' => $this->table(),
            'Item' => $latest,
        ]);
    }

    /**
     * model_number の LATEST を一括取得
     */
    public function batchGetLatestByModel(array $modelNumbers): array
    {
        $table = $this->table();

        // 空や重複を除外
        $modelNumbers = array_values(array_unique(array_filter(array_map('strval', $modelNumbers))));
        if (empty($modelNumbers)) return [];

        $all = [];

        // BatchGetは安全に 100件単位で
        foreach (array_chunk($modelNumbers, 100) as $chunk) {
            $requestKeys = array_map(fn($m) => [
                'model_number' => ['S' => $m],
                'time_id'      => ['S' => 'LATEST'],
            ], $chunk);

            $requestItems = [
                $table => ['Keys' => $requestKeys],
            ];

            // UnprocessedKeys がある前提でリトライ
            for ($i = 0; $i < 5; $i++) {
                $res = $this->ddb->batchGetItem(['RequestItems' => $requestItems]);

                $items = $res['Responses'][$table] ?? [];
                $all = array_merge($all, $items);

                $unprocessed = $res['UnprocessedKeys'] ?? [];
                if (empty($unprocessed)) break;

                $requestItems = $unprocessed;
                usleep(200000 * ($i + 1)); // 0.2s, 0.4s...
            }
        }

        return $all;
    }
}