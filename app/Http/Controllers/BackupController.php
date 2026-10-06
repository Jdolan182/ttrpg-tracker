<?php

namespace App\Http\Controllers;

use App\Models\Encounter;
use App\Support\Backup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Downloading and restoring backups. Guests export a fight from the tracker in the browser
 * (resources/js/lib/backup.ts); importing always saves to an account.
 */
class BackupController extends Controller
{
    /** Every creature and encounter the user has made. */
    public function export(Request $request): JsonResponse
    {
        return $this->download(Backup::everything($request->user()), 'backup');
    }

    public function exportEncounter(Encounter $encounter): JsonResponse
    {
        Gate::authorize('update', $encounter);

        return $this->download(Backup::encounter($encounter), Str::slug($encounter->name) ?: 'encounter');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'extensions:json', 'max:'.Backup::MAX_KILOBYTES]],
            ['file.extensions' => 'Choose a .json backup file.', 'file.max' => 'That file is too big to be a backup.'],
        );

        $data = json_decode($request->file('file')->get(), true);
        if (! is_array($data)) {
            throw ValidationException::withMessages(['backup' => "That file isn't a backup: it couldn't be read."]);
        }

        $result = Backup::import($request->user(), $data);

        return back()->with('imported', self::summary($result));
    }

    /**
     * @param  array{creatures: int, reused: int, encounters: int, missing: int}  $result
     */
    private static function summary(array $result): string
    {
        $parts = array_filter([
            $result['creatures'] ? trans_choice('{1} 1 creature|[2,*] :count creatures', $result['creatures']) : null,
            $result['encounters'] ? trans_choice('{1} 1 encounter|[2,*] :count encounters', $result['encounters']) : null,
        ]);
        $summary = $parts ? 'Imported '.implode(' and ', $parts).'.' : 'Nothing new to import.';

        if ($result['reused']) {
            $summary .= ' '.trans_choice('{1} 1 creature was already in your compendium, so it was reused.|[2,*] :count creatures were already in your compendium, so they were reused.', $result['reused']);
        }
        if ($result['missing']) {
            $summary .= ' '.trans_choice("{1} 1 combatant's creature couldn't be found, so it was added without a stat block.|[2,*] :count combatants' creatures couldn't be found, so they were added without stat blocks.", $result['missing']);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $backup
     */
    private function download(array $backup, string $name): JsonResponse
    {
        $filename = 'turnkeeper-'.$name.'-'.now()->format('Y-m-d').'.json';

        return response()->json($backup, 200, ['Content-Disposition' => "attachment; filename=\"{$filename}\""], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
