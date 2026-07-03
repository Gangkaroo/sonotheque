<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use Illuminate\Http\JsonResponse;

class DashboardMetricsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'artists' => Artist::query()
                ->where(fn ($query) => $query->whereHas('albums')->orWhereHas('tracks'))
                ->count(),
            'albums' => Album::query()->count(),
            'tracks' => Track::query()->count(),
            'genres' => Genre::query()->has('tracks')->count(),
            'playedAlbums' => Album::query()
                ->whereHas('tracks.playStatistic', fn ($query) => $query->where('play_count', '>', 0))
                ->count(),
            'playedTracks' => TrackPlayStatistic::query()
                ->where('play_count', '>', 0)
                ->count(),
        ]);
    }
}
