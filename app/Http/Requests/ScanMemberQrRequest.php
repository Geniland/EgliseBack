<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanMemberQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $all = $this->all();

        $candidates = [
            $all['qr_payload'] ?? null,
            $all['payload'] ?? null,
            $all['data'] ?? null,
            $all['member_qr'] ?? null,
            $all['qr'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && ($c[0] ?? '') === '{') {
                try {
                    $json = json_decode($c, true, 4);
                    if (is_array($json)) {
                        $all = array_merge($all, $json);
                    }
                } catch (\Throwable) { /* ignore */ }
            } elseif (is_array($c)) {
                $all = array_merge($all, $c);
            }
        }

        if (!empty($all['t']) && empty($all['member_qr_token'])) $all['member_qr_token'] = $all['t'];
        if (!empty($all['token']) && empty($all['member_qr_token'])) $all['member_qr_token'] = $all['token'];
        if (!empty($all['member_token']) && empty($all['member_qr_token'])) $all['member_qr_token'] = $all['member_token'];
        if (!empty($all['mid']) && empty($all['member_id'])) $all['member_id'] = $all['mid'];
        if (!empty($all['code']) && empty($all['member_code'])) $all['member_code'] = $all['code'];
        if (!empty($all['sid']) && empty($all['session_id'])) $all['session_id'] = $all['sid'];
        if (!empty($all['s']) && empty($all['session_id'])) $all['session_id'] = $all['s'];

        $coords = $all['coords'] ?? null;
        if (is_array($coords)) {
            if (empty($all['latitude']) && isset($coords['latitude'])) $all['latitude'] = $coords['latitude'];
            if (empty($all['longitude']) && isset($coords['longitude'])) $all['longitude'] = $coords['longitude'];
            if (empty($all['accuracy']) && isset($coords['accuracy'])) $all['accuracy'] = $coords['accuracy'];
            if (empty($all['latitude']) && isset($coords['lat'])) $all['latitude'] = $coords['lat'];
            if (empty($all['longitude']) && isset($coords['lng'])) $all['longitude'] = $coords['lng'];
        }
        if (empty($all['latitude']) && isset($all['lat'])) $all['latitude'] = $all['lat'];
        if (empty($all['longitude']) && isset($all['lng'])) $all['longitude'] = $all['lng'];
        if (empty($all['longitude']) && isset($all['long'])) $all['longitude'] = $all['long'];
        if (empty($all['longitude']) && isset($all['lon'])) $all['longitude'] = $all['lon'];
        foreach (['position', 'gps', 'location'] as $k) {
            $sub = $all[$k] ?? null;
            if (is_array($sub)) {
                if (empty($all['latitude'])) $all['latitude'] = $sub['latitude'] ?? $sub['lat'] ?? $all['latitude'] ?? null;
                if (empty($all['longitude'])) $all['longitude'] = $sub['longitude'] ?? $sub['lng'] ?? $sub['lon'] ?? $sub['long'] ?? $all['longitude'] ?? null;
                if (empty($all['accuracy'])) $all['accuracy'] = $sub['accuracy'] ?? $all['accuracy'] ?? null;
            }
        }

        foreach (['latitude', 'longitude', 'accuracy'] as $k) {
            if (array_key_exists($k, $all) && ($all[$k] === '' || $all[$k] === null)) {
                $all[$k] = null;
            }
        }

        $this->merge($all);
    }

    public function rules(): array
    {
        return [
            'session_id' => 'required|integer|exists:attendance_sessions,id',
            'member_qr_token' => 'required_without:member_id,member_code|string|exists:members,qr_token',
            'member_code' => 'required_without:member_qr_token,member_id|string|exists:members,member_code',
            'member_id' => 'required_without:member_qr_token,member_code|integer|exists:members,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ];
    }

    public function getNormalizedGps(): ?array
    {
        $lat = $this->input('latitude');
        $lng = $this->input('longitude');
        if ($lat === null || $lng === null) return null;
        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'accuracy' => $this->input('accuracy') === null ? null : (float) $this->input('accuracy'),
        ];
    }
}
