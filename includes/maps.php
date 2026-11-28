<?php
/**
 * SokoHub Google Maps Integration & Delivery Routing Engine
 * Provides embed URLs, direct navigation links, and styled HTML components
 * for Seller Shop Locations and Delivery Route Tracking across Kirinyaga County.
 */

if (!function_exists('get_kirinyaga_coordinates')) {
    /**
     * Known geographic coordinates for key hubs in Kirinyaga County, Kenya.
     */
    function get_kirinyaga_coordinates(string $town): array
    {
        $normalized = strtolower(trim($town));
        $coords = [
            'kutus'    => ['lat' => -0.5186, 'lng' => 37.3382, 'label' => 'Kutus Central Hub'],
            'kerugoya' => ['lat' => -0.4989, 'lng' => 37.2803, 'label' => 'Kerugoya Commercial Center'],
            'kagio'    => ['lat' => -0.5694, 'lng' => 37.2583, 'label' => 'Kagio Market & Rail Hub'],
            'sagana'   => ['lat' => -0.6657, 'lng' => 37.2008, 'label' => 'Sagana Junction & River Hub'],
            'wanguru'  => ['lat' => -0.6869, 'lng' => 37.3592, 'label' => "Wang'uru (Mwea Rice Belt)"],
            'mwea'     => ['lat' => -0.6869, 'lng' => 37.3592, 'label' => 'Mwea / Wanguru'],
            'baricho'  => ['lat' => -0.5367, 'lng' => 37.2289, 'label' => 'Baricho West Hub'],
        ];

        foreach ($coords as $key => $val) {
            if (str_contains($normalized, $key)) {
                return $val;
            }
        }
        // Default to Kutus (County HQ & SokoHub central dispatch)
        return ['lat' => -0.5186, 'lng' => 37.3382, 'label' => 'Kirinyaga County, Kenya'];
    }
}

if (!function_exists('google_map_embed_url')) {
    /**
     * Builds safe, responsive Google Maps Embed URL for a shop or location.
     */
    function google_map_embed_url(string $query, int $zoom = 15): string
    {
        $clean = trim($query);
        if ($clean === '') {
            $clean = 'Kutus, Kirinyaga, Kenya';
        } elseif (!str_contains(strtolower($clean), 'kenya')) {
            $clean .= ', Kirinyaga, Kenya';
        }
        return 'https://www.google.com/maps?q=' . urlencode($clean) . '&output=embed&z=' . $zoom;
    }
}

if (!function_exists('google_map_directions_embed_url')) {
    /**
     * Builds safe Google Maps Embed URL showing routing between origin and destination.
     */
    function google_map_directions_embed_url(string $origin, string $destination): string
    {
        $saddr = trim($origin);
        $daddr = trim($destination);

        if ($saddr === '') {
            $saddr = 'Kutus, Kirinyaga, Kenya';
        } elseif (!str_contains(strtolower($saddr), 'kenya')) {
            $saddr .= ', Kirinyaga, Kenya';
        }

        if ($daddr === '') {
            $daddr = 'Kerugoya, Kirinyaga, Kenya';
        } elseif (!str_contains(strtolower($daddr), 'kenya')) {
            $daddr .= ', Kirinyaga, Kenya';
        }

        return 'https://maps.google.com/maps?saddr=' . urlencode($saddr) . '&daddr=' . urlencode($daddr) . '&output=embed';
    }
}

if (!function_exists('google_map_search_url')) {
    /**
     * External Google Maps URL for opening in browser or Google Maps mobile app.
     */
    function google_map_search_url(string $query): string
    {
        $clean = trim($query);
        if (!str_contains(strtolower($clean), 'kenya')) {
            $clean .= ', Kirinyaga, Kenya';
        }
        return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($clean);
    }
}

if (!function_exists('google_map_directions_url')) {
    /**
     * External Google Maps Turn-by-Turn GPS navigation URL.
     */
    function google_map_directions_url(string $origin, string $destination): string
    {
        $saddr = trim($origin);
        $daddr = trim($destination);
        if (!str_contains(strtolower($saddr), 'kenya')) {
            $saddr .= ', Kirinyaga, Kenya';
        }
        if (!str_contains(strtolower($daddr), 'kenya')) {
            $daddr .= ', Kirinyaga, Kenya';
        }
        return 'https://www.google.com/maps/dir/?api=1&origin=' . urlencode($saddr) . '&destination=' . urlencode($daddr) . '&travelmode=driving';
    }
}

if (!function_exists('render_shop_map_card')) {
    /**
     * Renders a styled HTML card with an embedded Google Map for a seller shop location.
     */
    function render_shop_map_card(string $businessName, string $town, array $options = []): string
    {
        $height = (int) ($options['height'] ?? 280);
        $showDirectionsBtn = $options['show_directions'] ?? true;
        $title = $businessName !== '' ? $businessName : 'Seller Store';
        $locationLabel = $town !== '' ? $town : 'Kutus, Kirinyaga';

        $query = $title . ', ' . $locationLabel;
        $embedUrl = google_map_embed_url($query, 15);
        $externalUrl = google_map_search_url($query);
        $dirUrl = google_map_directions_url('My Location', $query);

        $html = '<div class="shop-location-map-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); margin-top:1rem;">';
        
        // Header
        $html .= '<div style="padding:0.75rem 1rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">';
        $html .= '  <div style="display:flex; align-items:center; gap:0.5rem;">';
        $html .= '    ' . icon('store', 'lucide-sm', 'emerald');
        $html .= '    <div>';
        $html .= '      <div style="font-weight:700; font-size:0.92rem; color:#0f172a;">' . e($title) . '</div>';
        $html .= '      <div style="font-size:0.8rem; color:#64748b; display:flex; align-items:center; gap:0.25rem;">';
        $html .= '        ' . icon('map-pin', 'lucide-xs', 'rose') . ' <span>' . e($locationLabel) . '</span>';
        $html .= '      </div>';
        $html .= '    </div>';
        $html .= '  </div>';
        $html .= '  <span class="badge badge-success" style="font-size:0.75rem; padding:0.25rem 0.6rem;">' . icon('check-circle', 'lucide-xs') . ' Physical Shop</span>';
        $html .= '</div>';

        // Google Map Iframe Container
        $html .= '<div style="position:relative; width:100%; height:' . $height . 'px; background:#e2e8f0;">';
        $html .= '  <iframe src="' . e($embedUrl) . '" width="100%" height="100%" style="border:0; display:block;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Google Map of ' . e($title) . '"></iframe>';
        $html .= '</div>';

        // Footer Actions
        $html .= '<div style="padding:0.75rem 1rem; background:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">';
        $html .= '  <a href="' . e($externalUrl) . '" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.8rem;">';
        $html .= '    ' . icon('external-link', 'lucide-xs', 'blue') . ' <span>Open in Google Maps</span>';
        $html .= '  </a>';
        if ($showDirectionsBtn) {
            $html .= '  <a href="' . e($dirUrl) . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.8rem;">';
            $html .= '    ' . icon('navigation', 'lucide-xs', 'white') . ' <span>Get Directions</span>';
            $html .= '  </a>';
        }
        $html .= '</div>';

        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('render_delivery_tracking_map_card')) {
    /**
     * Renders a live delivery route tracking card with Google Maps route embed and transit checkpoints.
     */
    function render_delivery_tracking_map_card(array $delivery, array $options = []): string
    {
        $height = (int) ($options['height'] ?? 320);
        $pickup = trim((string) ($delivery['pickup_town'] ?? 'Kutus Market'));
        $dropoff = trim((string) ($delivery['dropoff_address'] ?? 'Kerugoya Town'));
        $status = (string) ($delivery['delivery_status'] ?? 'pending');
        $orderCode = (string) ($delivery['order_code'] ?? '');
        $riderName = (string) ($delivery['rider_name'] ?? '');
        $riderPhone = (string) ($delivery['rider_phone'] ?? '');

        $embedUrl = google_map_directions_embed_url($pickup, $dropoff);
        $navUrl = google_map_directions_url($pickup, $dropoff);

        // Status badge configuration
        $statusMeta = [
            'pending' => [
                'badge' => 'badge-warning',
                'label' => 'Pending Dispatch',
                'desc'  => 'Order packed at store; awaiting rider dispatch assignment.',
                'color' => '#d97706',
            ],
            'assigned' => [
                'badge' => 'badge-info',
                'label' => 'Rider Assigned',
                'desc'  => 'Rider dispatched to collect package from store.',
                'color' => '#2563eb',
            ],
            'picked_up' => [
                'badge' => 'badge-info',
                'label' => 'Package Picked Up',
                'desc'  => 'Rider collected item from store; departing to drop-off.',
                'color' => '#0284c7',
            ],
            'in_transit' => [
                'badge' => 'badge-primary',
                'label' => 'In Transit',
                'desc'  => 'Delivery rider is actively in transit towards your location.',
                'color' => '#059669',
            ],
            'out_for_delivery' => [
                'badge' => 'badge-primary',
                'label' => 'Out for Delivery',
                'desc'  => 'Rider has arrived in your zone and is approaching drop-off.',
                'color' => '#16a34a',
            ],
            'delivered' => [
                'badge' => 'badge-success',
                'label' => 'Delivered',
                'desc'  => 'Package safely handed over to the customer.',
                'color' => '#15803d',
            ],
            'failed' => [
                'badge' => 'badge-error',
                'label' => 'Delivery Issue',
                'desc'  => 'Delivery could not be completed. Support notified.',
                'color' => '#dc2626',
            ],
        ];

        $currentMeta = $statusMeta[$status] ?? $statusMeta['in_transit'];

        $html = '<div class="delivery-tracking-map-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,0.06); margin-top:1rem;">';

        // Header: Order Code & Live Status
        $html .= '<div style="padding:1rem 1.25rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">';
        $html .= '  <div>';
        $html .= '    <div style="display:flex; align-items:center; gap:0.4rem; font-weight:700; color:#0f172a; font-size:0.95rem;">';
        $html .= '      ' . icon('bike', 'lucide-sm', 'emerald') . ' <span>Live Route &amp; Delivery Tracking</span>';
        if ($orderCode !== '') {
            $html .= '      <span style="color:#64748b; font-weight:400; font-size:0.85rem;">(' . e($orderCode) . ')</span>';
        }
        $html .= '    </div>';
        $html .= '    <div style="font-size:0.8rem; color:#475569; margin-top:0.2rem;">' . e($currentMeta['desc']) . '</div>';
        $html .= '  </div>';
        $html .= '  <div><span class="badge ' . e($currentMeta['badge']) . '" style="padding:0.35rem 0.75rem; font-size:0.8rem; font-weight:600;">' . e($currentMeta['label']) . '</span></div>';
        $html .= '</div>';

        // Route Checkpoints Bar
        $html .= '<div style="padding:0.75rem 1.25rem; background:#ffffff; border-bottom:1px solid #f1f5f9; display:grid; grid-template-columns:1fr auto 1fr; gap:0.75rem; align-items:center; font-size:0.85rem;">';
        // Origin (Shop)
        $html .= '  <div style="display:flex; align-items:flex-start; gap:0.4rem;">';
        $html .= '    ' . icon('store', 'lucide-xs', 'emerald');
        $html .= '    <div>';
        $html .= '      <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:#64748b;">Pickup (Shop)</div>';
        $html .= '      <div style="font-weight:600; color:#0f172a;">' . e($pickup) . '</div>';
        $html .= '    </div>';
        $html .= '  </div>';

        // Center Arrow
        $html .= '  <div style="display:flex; align-items:center; justify-content:center; color:#94a3b8;">';
        $html .= '    ' . icon('arrow-right', 'lucide-sm');
        $html .= '  </div>';

        // Destination (Dropoff)
        $html .= '  <div style="display:flex; align-items:flex-start; gap:0.4rem; justify-content:flex-end; text-align:right;">';
        $html .= '    <div>';
        $html .= '      <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:#64748b;">Drop-off</div>';
        $html .= '      <div style="font-weight:600; color:#0f172a;">' . e($dropoff) . '</div>';
        $html .= '    </div>';
        $html .= '    ' . icon('map-pin', 'lucide-xs', 'rose');
        $html .= '  </div>';
        $html .= '</div>';

        // Google Map Route Embed
        $html .= '<div style="position:relative; width:100%; height:' . $height . 'px; background:#e2e8f0;">';
        $html .= '  <iframe src="' . e($embedUrl) . '" width="100%" height="100%" style="border:0; display:block;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Google Map Delivery Route"></iframe>';
        $html .= '</div>';

        // Footer: Rider Info & Navigation Launch
        $html .= '<div style="padding:0.75rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">';
        $html .= '  <div>';
        if ($riderName !== '') {
            $html .= '    <span style="font-size:0.8rem; color:#475569;">Assigned Rider: <strong style="color:#0f172a;">' . e($riderName) . '</strong>';
            if ($riderPhone !== '') {
                $html .= ' &bull; <a href="tel:' . e($riderPhone) . '" style="color:#16a34a; font-weight:600; text-decoration:none;">' . icon('phone', 'lucide-xs') . ' ' . e($riderPhone) . '</a>';
            }
            $html .= '    </span>';
        } else {
            $html .= '    <span style="font-size:0.8rem; color:#64748b;">Direct local dispatch across Kirinyaga County hubs</span>';
        }
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '    <a href="' . e($navUrl) . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:0.4rem; font-size:0.8rem;">';
        $html .= '      ' . icon('navigation', 'lucide-xs', 'white') . ' <span>Open Route in Google Maps</span>';
        $html .= '    </a>';
        $html .= '  </div>';
        $html .= '</div>';

        $html .= '</div>';
        return $html;
    }
}
