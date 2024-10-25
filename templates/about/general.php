<!-- about general tab -->
<div class="tab-pane active" id="aboutgeneral">
  <div class="row">
    <div class="col-md-6 mt-3">
      <div class="card">
	    <div class="card-body">
          <div class="ms-5 mt-2"><img class="about-logo" src="app/img/raspAP-logo.php" style="width: 175px; height:175px"></div>
          <h2 class="mt-3 ms-4"><?php echo _("RaspAP") ." v".RASPI_VERSION; ?></h2>
          <?php if (!RASPI_MONITOR_ENABLED) : ?>
          <button type="button" class="btn btn-warning ms-4 mt-2" name="check-update" data-bs-toggle="modal" data-bs-target="#chkupdateModal" />
            <i class="fas fa-sync-alt ms-1 me-2"></i><?php echo _("Check for update"); ?>
          </button>
          <?php endif; ?>
        </div>
      </div>
     </div>
     <div class="col-md-8">
      <div class="mt-3">RaspAP is a co-creation of <a href="https://github.com/billz">billz</a> and <a href="https://github.com/sirlagz">SirLagz</a>
        with the contributions of our <a href="https://github.com/raspap/raspap-webgui/graphs/contributors">developer community</a>
        and <a href="https://crowdin.com/project/raspap">language translators</a>.
        Learn more about joining the project as a <a href="https://docs.raspap.com/#get-involved">code contributor</a>,
      <a href="https://docs.raspap.com/translations/">translator</a> or <a href="https://github.com/sponsors/RaspAP">financial sponsor</a> with immediate access to <a href="https://docs.raspap.com/insiders/#whats-in-it-for-me">exclusive features</a> available to <strong>Insiders</strong>.</div>
      <div class="mt-3 project-links">
        <div class="row">
          <div class="col-md-6">GitHub <i class="fab fa-github"></i> <a href="https://github.com/RaspAP/">RaspAP</a></div>
          <div class="col-md-6">Twitter <i class="fab fa-twitter"></i> <a href="https://twitter.com/rasp_ap">@RaspAP</a></div>
          <div class="col-md-6">Reddit <i class="fab fa-reddit"></i> <a href="https://www.reddit.com/r/RaspAP/">/r/RaspAP</a></div>
          <div class="col-md-6">Discord <i class="fab fa-discord"></i> <a href="https://discord.gg/KVAsaAR">RaspAP</a></div>
          <div class="col-md-6">Docs <i class="fas fa-book-reader"></i> <a href="https://docs.raspap.com/">docs.raspap.com</a></div>
          <div class="col-md-6">License <i class="fas fa-balance-scale"></i> <a href="https://github.com/raspap/raspap-webgui/blob/master/LICENSE">GPL-3.0</a></div>
        </div>
      </div>
    </div>
  </div><!-- /.row -->
</div><!-- /.tab-pane | general tab -->
