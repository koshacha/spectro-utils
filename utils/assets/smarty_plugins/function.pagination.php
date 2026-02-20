<?php
/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     function.pagination.php
 * Type:     function
 * Name:     pagination
 * Purpose:  Outputs a pagination control.
 * -------------------------------------------------------------
 *
 * @param array $params
 * - base_url: (string) The base URL for pagination links.
 * - total_pages: (int) The total number of pages.
 * - current_page: (int, optional) The current page number. Defaults to 1.
 * - page_param: (string, optional) The name of the GET parameter for the page. Defaults to 'page'.
 * - window: (int, optional) The number of page links to show around the current page. Defaults to 2.
 * @param Smarty_Internal_Template $smarty The Smarty template instance.
 * @return string The HTML for the pagination control.
 */
function smarty_function_pagination($params, &$smarty)
{
    // --- Parameters ---
    $base_url = $params['base_url'] ?? '';
    $total_pages = isset($params['total_pages']) ? (int)$params['total_pages'] : 0;
    $current_page = isset($params['current_page']) ? (int)$params['current_page'] : 1;
    $page_param = $params['page_param'] ?? 'page';
    $window = isset($params['window']) ? (int)$params['window'] : 2;

    // No pagination needed if there is only one page or less.
    if ($total_pages <= 1) {
        return '';
    }

    // --- Helper function to generate URLs ---
    $make_url = function ($page) use ($base_url, $page_param) {
        // Check if the base_url already contains a query string.
        $separator = strpos($base_url, '?') === false ? '?' : '&';
        return htmlspecialchars($base_url . $separator . $page_param . '=' . $page);
    };

    // --- CSS Styles (Bootstrap-like) ---
    $html = '<style>
        .pagination { display: flex; flex-wrap: wrap; list-style: none; padding: 0; margin: 1rem 0; border-radius: 4px; }
        .pagination li a, .pagination li span { 
            position: relative;
            display: block; 
            padding: 0.5rem 0.75rem; 
            margin-left: -1px;
            line-height: 1.25; 
            color: #007bff; 
            background-color: #fff; 
            border: 1px solid #dee2e6; 
            text-decoration: none;
            transition: color .15s ease-in-out,background-color .15s ease-in-out,border-color .15s ease-in-out,box-shadow .15s ease-in-out;
        }
        .pagination li:first-child a, .pagination li:first-child span { margin-left: 0; border-top-left-radius: 4px; border-bottom-left-radius: 4px; }
        .pagination li:last-child a, .pagination li:last-child span { border-top-right-radius: 4px; border-bottom-right-radius: 4px; }
        .pagination li a:hover { color: #0056b3; background-color: #e9ecef; border-color: #dee2e6; }
        .pagination li.active a, .pagination li.active span { z-index: 1; color: #fff; background-color: #007bff; border-color: #007bff; }
        .pagination li.disabled span { color: #6c757d; pointer-events: none; cursor: auto; background-color: #fff; border-color: #dee2e6; }
        .pagination li.dots span { border-color: transparent; background-color: transparent; color: #6c757d; }
    </style>';

    $html .= '<ul class="pagination">';

    // --- "Previous" Button ---
    if ($current_page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $make_url($current_page - 1) . '">Предыдущая</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Предыдущая</span></li>';
    }

    // --- Page Number Links ---
    // Logic to show a window of pages instead of all of them.
    if ($total_pages > ($window * 2) + 3) {
        // Page 1
        $html .= '<li class="page-item ' . ($current_page == 1 ? 'active' : '') . '"><a class="page-link" href="' . $make_url(1) . '">1</a></li>';

        // Ellipsis after page 1
        if ($current_page > $window + 2) {
            $html .= '<li class="page-item dots disabled"><span class="page-link">...</span></li>';
        }

        // Window pages
        $start = max(2, $current_page - $window);
        $end = min($total_pages - 1, $current_page + $window);

        for ($i = $start; $i <= $end; $i++) {
            $html .= '<li class="page-item ' . ($current_page == $i ? 'active' : '') . '"><a class="page-link" href="' . $make_url($i) . '">' . $i . '</a></li>';
        }

        // Ellipsis before last page
        if ($current_page < $total_pages - $window - 1) {
            $html .= '<li class="page-item dots disabled"><span class="page-link">...</span></li>';
        }
        
        // Last page
        $html .= '<li class="page-item ' . ($current_page == $total_pages ? 'active' : '') . '"><a class="page-link" href="' . $make_url($total_pages) . '">' . $total_pages . '</a></li>';

    } else { // Show all pages if there aren't too many
        for ($i = 1; $i <= $total_pages; $i++) {
            $html .= '<li class="page-item ' . ($current_page == $i ? 'active' : '') . '"><a class="page-link" href="' . $make_url($i) . '">' . $i . '</a></li>';
        }
    }

    // --- "Next" Button ---
    if ($current_page < $total_pages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $make_url($current_page + 1) . '">Следующая</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Следующая</span></li>';
    }

    $html .= '</ul>';

    return $html;
}
