<?php

namespace App\Http\Middleware;

use App\Checklists\ChecklistSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup rute fitur yang dimatikan Administrator (Pengaturan > Fitur) dengan 404, termasuk permintaan JSON.
 * Pemetaan memakai nama rute: kunci yang berakhir titik = awalan, selain itu nama persis. Satu rute bisa butuh beberapa fitur
 * (Peta kamar = Laporan + Kendala). Menu disembunyikan lewat gate di AppServiceProvider.
 */
class EnsureFeatureEnabled
{
    public const ROUTES = [
        'permintaan.' => ['permintaan'],
        'kamar.' => ['kendala'],
        'laporan.rooms' => ['kendala'],
        'laporan.room' => ['kendala'],
        'laporan.building' => ['kendala'],
        'laporan.requests' => ['permintaan'],
        'chat.issue' => ['kendala'],
        'chat.share-issue' => ['kendala'],
        'chronology.' => ['chronology'],
        'chat.share-chronology' => ['chronology'],
        'lost.' => ['obrolan'],
        'laporan.' => ['laporan'],
    ];

    public function __construct(private ChecklistSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        if ($name !== null) {
            foreach (self::features($name) as $feature) {
                abort_unless($this->settings->featureEnabled($feature), 404);
            }
        }

        return $next($request);
    }

    /** @return list<string> */
    public static function features(string $routeName): array
    {
        $features = [];
        foreach (self::ROUTES as $key => $needed) {
            if (str_ends_with($key, '.') ? str_starts_with($routeName, $key) : $routeName === $key) {
                array_push($features, ...$needed);
            }
        }

        return array_values(array_unique($features));
    }
}
