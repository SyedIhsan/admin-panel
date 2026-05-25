<?php
// partials/demo-banner.php
// Include this just after <body> opens in partials/header.php
?>
<div id="demo-banner" style="
    position: sticky;
    top: 0;
    z-index: 9999;
    background: linear-gradient(90deg, #f59e0b 0%, #ef4444 100%);
    color: white;
    padding: 8px 16px;
    text-align: center;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
">
    <span>🎭 <b>DEMO MODE</b></span>
    <span class="hidden sm:inline">All data is fake · No real charges or emails · Resets daily</span>
    <span class="sm:hidden">Sample data only</span>
    <a href="https://github.com/YOUR_USERNAME/YOUR_REPO" target="_blank"
       style="background: rgba(255,255,255,0.2); padding: 2px 10px; border-radius: 4px; color: white; text-decoration: none; font-size: 12px;">
        View Source ↗
    </a>
    <button onclick="document.getElementById('demo-banner').style.display='none'"
            style="background: transparent; border: 0; color: white; cursor: pointer; padding: 0 4px; font-size: 16px; line-height: 1;"
            aria-label="Dismiss">×</button>
</div>
