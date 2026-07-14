<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->unsignedSmallInteger('metadata_parser_version')
                ->default(3)
                ->change();
        });

        DB::table('media_files')->update(['metadata_parser_version' => 3]);

        $legacyGenreIds = DB::table('genres')
            ->get(['id', 'name'])
            ->filter(static fn (object $genre): bool => preg_match(
                '/^(?:\((?:\d{1,3}|RX|CR)\))+/i',
                $genre->name,
            ) === 1 || preg_match('/^\d{1,3}$/', $genre->name) === 1)
            ->pluck('id');

        if ($legacyGenreIds->isEmpty()) {
            return;
        }

        DB::table('media_files')
            ->whereIn('id', function ($query) use ($legacyGenreIds): void {
                $query
                    ->select('tracks.media_file_id')
                    ->from('tracks')
                    ->join('genre_track', 'genre_track.track_id', '=', 'tracks.id')
                    ->whereIn('genre_track.genre_id', $legacyGenreIds);
            })
            ->update(['metadata_parser_version' => 2]);
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->unsignedSmallInteger('metadata_parser_version')
                ->default(2)
                ->change();
        });
    }
};
