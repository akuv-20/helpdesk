<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Las categorías ITIL viven en GLPI; el portal no las siembra. En modo
     * demo (sin GLPI configurado) el wizard usa un árbol de ejemplo interno.
     */
    public function run(): void
    {
        //
    }
}
