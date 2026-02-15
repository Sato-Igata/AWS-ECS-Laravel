<?php

namespace App\Services\DynamoDb;

use Aws\DynamoDb\DynamoDbClient;

class LocationInfoUserTable
{
    public function __construct(
        private DynamoDbClient $ddb,
    ) {}

    private function table(): string
    {
        return config('dynamodb.tables.location_user');
    }

    /**
     * 履歴 + 最新(LATEST)を両方保存（推奨）
     * 前提：PK=user_id, SK=time_id
     */
    public function putLocation(array $data): void
    {
        $userId = (string)($data['user_id'] ?? '');
        $timeId = (string)($data['time_id'] ?? '');

        if ($userId === '' || $timeId === '') {
            throw new \InvalidArgumentException('user_id and time_id are required.');
        }

        $item = [
            'user_id'  => ['N' => (string)$userId],
            'time_id'  => ['S' => $timeId],
            'lat'      => ['S' => (string)($data['lat'] ?? '')],
            'lng'      => ['S' => (string)($data['lng'] ?? '')],
            'alt'      => ['S' => (string)($data['alt'] ?? '')],
            'acc'      => ['S' => (string)($data['acc'] ?? '')],
            'alt_acc'  => ['S' => (string)($data['alt_acc'] ?? '')],
            'stl'      => ['S' => (string)($data['stl'] ?? '')],
            'vol'      => ['S' => (string)($data['vol'] ?? '')],
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
     * user_id の LATEST を一括取得
     */
    public function batchGetLatestByUser(array $userIds): array
    {
        $table = $this->table();

        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if (empty($userIds)) return [];

        $all = [];

        foreach (array_chunk($userIds, 100) as $chunk) {
            $requestKeys = array_map(fn($uid) => [
                'user_id' => ['N' => (string)$uid],
                'time_id' => ['S' => 'LATEST'],
            ], $chunk);

            $requestItems = [
                $table => ['Keys' => $requestKeys],
            ];

            for ($i = 0; $i < 5; $i++) {
                $res = $this->ddb->batchGetItem(['RequestItems' => $requestItems]);

                $items = $res['Responses'][$table] ?? [];
                $all = array_merge($all, $items);

                $unprocessed = $res['UnprocessedKeys'] ?? [];
                if (empty($unprocessed)) break;

                $requestItems = $unprocessed;
                usleep(200000 * ($i + 1));
            }
        }

        return $all;
    }
}
