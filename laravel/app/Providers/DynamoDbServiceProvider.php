<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Aws\DynamoDb\DynamoDbClient;

class DynamoDbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DynamoDbClient::class, function () {
            $cfg = [
                'region'  => config('dynamodb.region'),
                'version' => 'latest',
            ];

            $endpoint = config('dynamodb.endpoint');
            if (!empty($endpoint)) {
                $cfg['endpoint'] = $endpoint;
            }

            // credentials は指定しない（ECS Task Role / EC2 Role / IAM環境に任せる）
            return new DynamoDbClient($cfg);
        });
    }
}
