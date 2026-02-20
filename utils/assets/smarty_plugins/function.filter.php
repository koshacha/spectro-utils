<?php
/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     function.filter.php
 * Type:     function
 * Name:     filter
 * Purpose:  Outputs a filter component form.
 * -------------------------------------------------------------
 */
function smarty_function_filter($params, &$smarty)
{
    // --- Parameters ---
    $fields = $params['fields'] ?? [];
    $labels = $params['labels'] ?? [];
    $variants = $params['variants'] ?? [];
    $axis = $params['axis'] ?? 'y';
    $action = $params['action'] ?? '';
    $method = $params['method'] ?? 'get';

    if (empty($fields)) {
        return '';
    }

    $currentFilters = $_GET['filter'] ?? [];
    $layoutClass = ($axis === 'x') ? 'filter-form--axis-x' : 'filter-form--axis-y';

    // --- CSS Styles ---
    $html = '<style>
        .filter-form { display: flex; gap: 1.5rem; font-family: sans-serif; padding: 1rem; border: 1px solid #eee; border-radius: 8px; background-color: #fcfcfc; }
        .filter-form--axis-y { flex-direction: column; }
        .filter-form--axis-x { flex-direction: row; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 0.5rem; min-width: 180px; }
        .filter-group__label { font-weight: bold; margin-bottom: 0.25rem; font-size: 0.95rem; color: #333; }
        .filter-group__options { display: flex; flex-direction: column; gap: 0.5rem; border: 1px solid #ddd; padding: 0.75rem; border-radius: 4px; background-color: #fff; max-height: 200px; overflow-y: auto; }
        .filter-form--axis-x .filter-group { align-items: flex-start; }
        .filter-form--axis-x .filter-group__options { flex-direction: row; }
        .filter-group__option { display: flex; align-items: center; gap: 0.5rem; }
        .filter-group__option label { font-weight: normal; cursor: pointer; }
        .filter-form input[type="text"], .filter-form select { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .filter-form__actions { margin-top: 1rem; display: flex; gap: 0.5rem; align-items: center; }
        .filter-form--axis-x .filter-form__actions { align-self: flex-end; margin-top: 0; }
        .filter-form__button { display: inline-block; text-decoration: none; text-align: center; padding: 0.6rem 1.2rem; border: none; border-radius: 4px; background-color: #007bff; color: white; cursor: pointer; font-size: 1rem; }
        .filter-form__button:hover { background-color: #0056b3; }
        .filter-form__button--reset { background-color: #6c757d; }
        .filter-form__button--reset:hover { background-color: #5a6268; }
        .filter-group__options a { text-decoration: none; color: #007bff; padding: 0.2rem 0; }
        .filter-group__options a:hover { text-decoration: underline; }
        .filter-group__options a.active { font-weight: bold; text-decoration: underline; }
    </style>';

    // --- Form Start ---
    $html .= "<form action=\"{$action}\" method=\"{$method}\" class=\"filter-form {$layoutClass}\">";

    foreach ($fields as $fieldName => $inputType) {
        $inputType = str_replace('!', '', $inputType);
        $label = $labels[$fieldName] ?? ucfirst($fieldName);
        $fieldVariants = $variants[$fieldName] ?? [];
        $currentValue = $currentFilters[$fieldName] ?? null;

        $html .= "<div class=\"filter-group\">";
        $html .= "<strong class=\"filter-group__label\">{$label}</strong>";
        
        $inputName = "filter[{$fieldName}]";

        switch ($inputType) {
            case 'input':
                $escapedValue = htmlspecialchars($currentValue ?? '');
                $html .= "<input type=\"text\" name=\"{$inputName}\" value=\"{$escapedValue}\">";
                break;

            case 'select':
                $html .= "<select name=\"{$inputName}\">";
                $html .= "<option value=\"\">Все</option>";
                foreach ($fieldVariants as $variant) {
                    $escapedVariant = htmlspecialchars($variant);
                    $selected = (is_scalar($currentValue) && trim($currentValue) == trim($variant)) ? ' selected' : '';
                    $html .= "<option value=\"{$escapedVariant}\"{$selected}>{$escapedVariant}</option>";
                }
                $html .= "</select>";
                break;

            case 'checkbox':
            case 'radio':
            case 'link':
                if (empty($fieldVariants)) break;
                $html .= "<div class=\"filter-group__options\">";
                foreach ($fieldVariants as $variant) {
                    $id = "filter-" . $fieldName . "-" . preg_replace('/[^a-zA-Z0-9_-]/', '', $variant);
                    $escapedVariant = htmlspecialchars($variant);

                    if ($inputType === 'link') {
                        $currentQuery = $_GET;
                        $currentQuery['filter'][$fieldName] = $variant;
                        $href = htmlspecialchars($action . '?' . http_build_query($currentQuery));
                        $activeClass = (is_scalar($currentValue) && trim($currentValue) == trim($variant)) ? ' active' : '';
                        $html .= "<a href=\"{$href}\" class=\"{$activeClass}\">{$escapedVariant}</a>";
                    } else { 
                        $nameAttr = ($inputType === 'checkbox') ? "{$inputName}[]" : $inputName;
                        $isChecked = false;
                        if ($inputType === 'checkbox') {
                            $trimmedCurrentValues = is_array($currentValue) ? array_map('trim', $currentValue) : [];
                            $isChecked = in_array(trim($variant), $trimmedCurrentValues);
                        } else { // radio
                            $isChecked = (is_scalar($currentValue) && trim($currentValue) == trim($variant));
                        }
                        $checkedAttr = $isChecked ? ' checked' : '';

                        $html .= "<div class=\"filter-group__option\">";
                        $html .= "<input type=\"{$inputType}\" name=\"{$nameAttr}\" value=\"{$escapedVariant}\" id=\"{$id}\"{$checkedAttr}> ";
                        $html .= "<label for=\"{$id}\">{$escapedVariant}</label>";
                        $html .= "</div>";
                    }
                }
                $html .= "</div>";
                break;
        }

        $html .= "</div>"; // .filter-group
    }

    // --- Form Actions ---
    $html .= "<div class=\"filter-group filter-form__actions\">";
    $html .= "<button type=\"submit\" class=\"filter-form__button\">Применить</button>";

    if (!empty($currentFilters)) {
        $query = $_GET;
        unset($query['filter']);
        $resetUrl = htmlspecialchars($action . '?' . http_build_query($query));
        $html .= "<a href=\"{$resetUrl}\" class=\"filter-form__button filter-form__button--reset\">Сбросить</a>";
    }
    
    $html .= "</div>";

    $html .= "</form>";

    return $html;
}
