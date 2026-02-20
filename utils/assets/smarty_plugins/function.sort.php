<?php
/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     function.sort.php
 * Type:     function
 * Name:     sort
 * Purpose:  Outputs sorting controls (buttons or a select dropdown).
 * -------------------------------------------------------------
 */
function smarty_function_sort($params, &$smarty)
{
    // --- Parameters ---
    $fields = $params['fields'] ?? [];
    $variant = $params['variant'] ?? 'select';
    $action = $params['action'] ?? ''; // Base URL path, e.g., /products

    if (empty($fields)) {
        return '';
    }

    // --- Current State from GET parameters ---
    $currentSort = $_GET['sort'] ?? null;
    $currentOrder = $_GET['order'] ?? null;

    // --- CSS Styles ---
    $html = '<style>
        .sort-controls { display: flex; align-items: center; gap: 10px; font-family: sans-serif; }
        .sort-controls__label { font-weight: bold; font-size: 0.95rem; color: #333; }
        .sort-controls__select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; background-color: #fff; }
        .sort-controls__buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .sort-controls__button { display: inline-block; padding: 6px 12px; border: 1px solid #ccc; background-color: #f0f0f0; color: #333; text-decoration: none; border-radius: 4px; font-size: 0.9rem; transition: all 0.2s ease; }
        .sort-controls__button:hover { background-color: #e0e0e0; border-color: #bbb; }
        .sort-controls__button.active { background-color: #007bff; color: white; border-color: #007bff; }
    </style>';

    $html .= '<div class="sort-controls">';
    $html .= '<span class="sort-controls__label">Сортировать:</span>';

    // --- Build Controls ---
    if ($variant === 'select') {
        $html .= '<select class="sort-controls__select" onchange="if (this.value) window.location.href = this.value;">';
        
        $html .= '<option value="">По умолчанию</option>';

        foreach ($fields as $sortOption) {
            if (count($sortOption) < 3) continue;
            list($field, $order, $label) = $sortOption;

            $query = $_GET;
            $query['sort'] = $field;
            $query['order'] = $order;
            
            $url = htmlspecialchars($action . '?' . http_build_query($query));
            
            $isSelected = ($currentSort == $field && $currentOrder == $order);
            $selectedAttr = $isSelected ? ' selected' : '';

            $html .= "<option value=\"{$url}\"{$selectedAttr}>" . htmlspecialchars($label) . "</option>";
        }
        $html .= '</select>';

    } else { // 'buttons' variant
        $html .= '<div class="sort-controls__buttons">';
        foreach ($fields as $sortOption) {
            if (count($sortOption) < 3) continue;
            list($field, $order, $label) = $sortOption;

            $query = $_GET;
            $query['sort'] = $field;
            $query['order'] = $order;

            $url = htmlspecialchars($action . '?' . http_build_query($query));

            $isActive = ($currentSort == $field && $currentOrder == $order);
            $activeClass = $isActive ? ' active' : '';

            $html .= "<a href=\"{$url}\" class=\"sort-controls__button{$activeClass}\">" . htmlspecialchars($label) . "</a>";
        }
        $html .= '</div>';
    }

    $html .= '</div>'; // .sort-controls

    return $html;
}
