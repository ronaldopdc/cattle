<header class="app-header">
    <div class="header-container">
        <div class="logo-section">
            <img src="assets/logo.png" alt="Cattle Invest" class="app-logo">
        </div>

        <button class="menu-toggle" id="menuToggle" style="display: none;">
            <i class="fas fa-bars"></i>
        </button>

        <nav class="main-nav" id="mainNav">
            <a href="index.php" <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'class="active"' : '' ?>>
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="partners.php" <?= basename($_SERVER['PHP_SELF']) == 'partners.php' ? 'class="active"' : '' ?>>
                <i class="fas fa-users"></i> Parceiros
            </a>
            <a href="lots.php" <?= basename($_SERVER['PHP_SELF']) == 'lots.php' ? 'class="active"' : '' ?>>
                <i class="fas fa-box"></i> Lotes
            </a>
            <a href="partnerships.php" <?= basename($_SERVER['PHP_SELF']) == 'partnerships.php' ? 'class="active"' : '' ?>>
                <i class="fas fa-handshake"></i> Parcerias
            </a>
            <a href="contracts.php" <?= basename($_SERVER['PHP_SELF']) == 'contracts.php' ? 'class="active"' : '' ?>>
                <i class="fas fa-file-contract"></i> Contratos
            </a>
            <?php if (has_role('admin')): ?>
                <a href="users.php" <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'class="active"' : '' ?>>
                    <i class="fas fa-user-shield"></i> Usuários
                </a>
            <?php endif; ?>
            <a href="logout.php" style="margin-left: auto; color: #f87171;">
                <i class="fas fa-sign-out-alt"></i> Sair
            </a>
        </nav>
    </div>
</header>

<script>
    document.getElementById('menuToggle').addEventListener('click', function () {
        document.getElementById('mainNav').classList.toggle('active');
        const icon = this.querySelector('i');
        if (icon.classList.contains('fa-bars')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-times');
        } else {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });
</script>