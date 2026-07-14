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
                ->default(2);
        });

        $legacyGenreIds = DB::table('genres')
            ->where('name', 'like', '(%')
            ->get(['id', 'name'])
            ->filter(static fn (object $genre): bool => preg_match(
                '/^(?:\((?:\d+|RX|CR)\))+$/i',
                $genre->name,
            ) === 1)
            ->pluck('id');

        if ($legacyGenreIds->isNotEmpty()) {
            DB::table('media_files')
                ->whereIn('id', function ($query) use ($legacyGenreIds): void {
                    $query
                        ->select('tracks.media_file_id')
                        ->from('tracks')
                        ->join('genre_track', 'genre_track.track_id', '=', 'tracks.id')
                        ->whereIn('genre_track.genre_id', $legacyGenreIds);
                })
                ->update(['metadata_parser_version' => 1]);
        }
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropColumn('metadata_parser_version');
        });
    }
};
