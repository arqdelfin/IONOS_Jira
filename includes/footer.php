<?php
$footer_version = trim((string)get_env('N_VERSION', get_env('N_Version', '')));
if ($footer_version === '') {
    $footer_version = 'Version no definida';
}
$footer_fecha = date('d/m/Y');
?>
<style>
  .app-footer {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    height: 36px;
    background: #0d1f38;
    color: #f8fbff;
    border-top: 1px solid rgba(255,255,255,.15);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 14px;
    box-sizing: border-box;
    font-size: .84rem;
    z-index: 20;
  }
  .app-footer-left,
  .app-footer-right {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  @media (max-width: 768px) {
    .app-footer {
      height: auto;
      min-height: 44px;
      padding: 6px 10px;
      flex-direction: column;
      justify-content: center;
      gap: 2px;
      text-align: center;
    }
  }
</style>
<div class="app-footer" role="contentinfo">
  <div class="app-footer-left"><?php echo htmlspecialchars($footer_version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
  <div class="app-footer-right"><?php echo htmlspecialchars($footer_fecha, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
</div>
