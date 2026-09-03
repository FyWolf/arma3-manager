<?php

namespace FyWolf\Arma3Manager\Http\Requests;

/**
 * Granting or withdrawing a mod set for one server.
 */
class GrantModSetRequest extends ModSetApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The server's uuid, not the panel's numeric id: it is the
            // identifier both sides had before anything was provisioned, and it
            // survives a panel restore. A numeric id would be silently rewired
            // by the first restore from backup.
            'server' => ['required', 'string', 'max:64'],

            // The set's stable key, e.g. "ace-cba".
            'mod_set' => ['required', 'string', 'max:191'],

            // Billing's own identifier for whatever pays for this, so the two
            // sides can reconcile without sharing a database.
            'source' => ['nullable', 'string', 'max:191'],
        ];
    }
}
