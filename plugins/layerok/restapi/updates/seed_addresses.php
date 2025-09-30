<?php

namespace Layerok\Restapi\Updates;

use October\Rain\Database\Schema\Blueprint;
use Schema;
use October\Rain\Database\Updates\Migration;

class seedAddresses extends Migration
{
    public function up()
    {
        $this->seedAddresses();
    }

    public function down()
    {
    }
    private function seedAddresses()
    {
        $jsonPath = __DIR__ . '/addresses.json';

        if (!file_exists($jsonPath)) {
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (is_array($data)) {
            foreach ($data as $item) {
                \DB::table('addresses')->insert([
                    'name_ua' => $item['name_ua'] ?? '',
                    'name_ru' => $item['name_ru'] ?? '',
                    'lon' => $item['lon'] ?? '',
                    'lat' => $item['lat'] ?? '',
                    'suburb_ua' => $item['suburb_ua'] ?? '',
                    'suburb_ru' => $item['suburb_ru'] ?? '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}