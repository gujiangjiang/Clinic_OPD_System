<?php
/** views/partials/footer.php — 公共页脚 */
?>
</main>
<footer class="pv-footer">
    <span><?php echo pvw_e(PvSettings::get('site_title', '模拟 PACS 影像浏览器')); ?> · 独立测试组件 v<?php echo pvw_e(PV_VERSION); ?></span>
    <span class="pv-footer-dim">仅供 DICOM / PACS 接口联调测试</span>
</footer>
<?php if (!empty($extraJs)) { foreach ((array)$extraJs as $j) { ?>
<script src="<?php echo pvw_asset('js/' . $j); ?>"></script>
<?php } } ?>
</body>
</html>
