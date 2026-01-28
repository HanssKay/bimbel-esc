<?php
require_once '../config/menu.php';

function renderMenu($currentPage, $userRole = 'admin') {
    global $menuConfig;
    
    if (!isset($menuConfig[$userRole])) {
        return '<p class="text-red-500 p-4">Menu tidak tersedia untuk role ini</p>';
    }
    
    $html = '';
    $menuItems = $menuConfig[$userRole];
    
    foreach ($menuItems as $item) {
        $html .= renderMenuItem($item, $currentPage);
    }
    
    return $html;
}

function renderMenuItem($item, $currentPage) {
    $hasSubmenu = isset($item['submenu']);
    $isActive = isMenuActive($item, $currentPage);
    $icon = $item['icon'] ?? '';
    $title = htmlspecialchars($item['title']);
    $url = $item['url'] ?? '#';
    $class = $item['class'] ?? 'menu-item flex items-center px-4 py-3 hover:bg-blue-700';
    
    $html = '';
    
    if ($hasSubmenu) {
        // Menu dengan submenu
        $html .= '<div class="mb-1">';
        $html .= '<button class="dropdown-toggle w-full flex items-center justify-between px-4 py-3 hover:bg-blue-700' . ($isActive ? ' active bg-blue-900' : '') . '">';
        $html .= '<div class="flex items-center">';
        $html .= '<i class="' . $icon . ' mr-3"></i>';
        $html .= '<span>' . $title . '</span>';
        $html .= '</div>';
        $html .= '<i class="fas fa-chevron-left text-xs arrow transform-transition duration-500"></i>';
        $html .= '</button>';
        
        // Submenu
        $html .= '<div class="dropdown-submenu bg-blue-800"' . ($isActive ? ' style="display: block;"' : ' style="display: none;"') . '>';
        foreach ($item['submenu'] as $subItem) {
            $subIsActive = isMenuActive($subItem, $currentPage);
            $subUrl = $subItem['url'] ?? '#';
            $html .= '<a href="' . $subUrl . '" class="menu-item flex items-center px-8 py-2 text-blue-100 hover:text-white hover:bg-blue-700 text-sm border-t border-blue-700' . ($subIsActive ? ' active bg-blue-900' : '') . '">';
            // $html .= '<i class="fas fa-circle text-xs mr-3"></i>';
            $html .= '<span>' . htmlspecialchars($subItem['title']) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
        $html .= '</div>';
    } else {
        // Menu tunggal (termasuk Logout)
        $isLogout = strpos($url, 'logout') !== false;
        $linkClass = $class . ($isActive && !$isLogout ? ' active bg-blue-900' : '');
        
        $html .= '<a href="' . $url . '" class="' . $linkClass . '">';
        $html .= '<i class="' . $icon . ' mr-3"></i>';
        $html .= $title;
        $html .= '</a>';
    }
    
    return $html;
}

function isMenuActive($menuItem, $currentPage) {
    // Jika menu punya active array, cek apakah currentPage ada di dalamnya
    if (isset($menuItem['active'])) {
        return in_array($currentPage, $menuItem['active']);
    }
    
    // Jika menu punya submenu, cek semua submenu
    if (isset($menuItem['submenu'])) {
        foreach ($menuItem['submenu'] as $subItem) {
            if (isMenuActive($subItem, $currentPage)) {
                return true;
            }
        }
        return false;
    }
    
    // Jika tidak punya active array, bandingkan dengan url
    if (isset($menuItem['url'])) {
        return basename($menuItem['url']) === $currentPage;
    }
    
    return false;
}
?>