<?php
require_once 'config.php';
require_once 'simulation_rates.php';

$rateTable = sim_rate_table();
$baseUrl   = sim_base_url();
$simUrl    = $baseUrl . '/simulacao.php';

// Logo da Rialma: usa o arquivo se existir; senão, mostra um wordmark estilizado.
$rialmaLogoExists = file_exists(__DIR__ . '/assets/rialma.png');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulação de Parceria — Rialma | Cattle Invest</title>
    <meta name="description"
        content="Simule sua parceria de engorda de gado com a Rialma. Receba à vista em até 24h e gire seu capital mais vezes por ano.">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <!-- Máscara de moeda no padrão brasileiro (esta página não inclui header.php) -->
    <script src="assets/currency-br.js"></script>
    <style>
        body {
            background: radial-gradient(circle at top right, #1e293b, #0f172a);
            min-height: 100vh;
            padding: 2rem 1rem;
            color: #e2e8f0;
        }

        .sim-wrap {
            max-width: 1100px;
            margin: 0 auto;
        }

        .sim-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .brand-logos {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .brand-logos img.cattle {
            height: 58px;
        }

        .rialma-wordmark {
            display: flex;
            flex-direction: column;
            line-height: 1;
            padding-left: 1.25rem;
            border-left: 1px solid rgba(255, 255, 255, 0.15);
        }

        .rialma-wordmark .r-name {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            color: #16a34a;
        }

        .rialma-wordmark .r-sub {
            font-size: 0.62rem;
            font-weight: 600;
            letter-spacing: 0.28em;
            color: #94a3b8;
            text-transform: uppercase;
            margin-top: 0.25rem;
        }

        .rialma-logo-img {
            height: 54px;
            padding-left: 1.25rem;
            border-left: 1px solid rgba(255, 255, 255, 0.15);
        }

        .btn-back {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .btn-back:hover {
            color: var(--primary-color);
        }

        .hero {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .hero h1 {
            font-size: 2.1rem;
            font-weight: 800;
            margin: 0 0 0.75rem;
            background: linear-gradient(90deg, #38bdf8, #10b981);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero p {
            color: #94a3b8;
            max-width: 640px;
            margin: 0 auto;
            font-size: 1.02rem;
        }

        .sim-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 860px) {
            .sim-grid {
                grid-template-columns: 1fr;
            }
        }

        .panel {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 1.75rem;
        }

        .panel h2 {
            margin: 0 0 1.25rem;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: #f8fafc;
        }

        .field {
            margin-bottom: 1.25rem;
        }

        .field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.85rem;
            color: #cbd5e1;
            font-weight: 500;
        }

        .field .input-wrap {
            position: relative;
        }

        .field input {
            width: 100%;
            padding: 0.8rem 1rem;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            color: #f1f5f9;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .field input:focus {
            border-color: var(--primary-color);
        }

        .price-status {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 0.4rem;
            min-height: 1rem;
        }

        .days-chips {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }

        .day-chip {
            flex: 1;
            min-width: 70px;
            padding: 0.7rem 0.5rem;
            text-align: center;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(15, 23, 42, 0.6);
            cursor: pointer;
            transition: all 0.2s;
        }

        .day-chip .d-days {
            font-weight: 700;
            font-size: 0.95rem;
            color: #e2e8f0;
        }

        .day-chip .d-rate {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 0.15rem;
        }

        .day-chip.active {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.12);
            box-shadow: 0 0 0 1px #10b981 inset;
        }

        .day-chip.active .d-rate {
            color: #34d399;
        }

        /* Results */
        .result-hero {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(56, 189, 248, 0.08));
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 14px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.25rem;
        }

        .result-hero .r-label {
            font-size: 0.8rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .result-hero .r-total {
            font-size: 2.4rem;
            font-weight: 800;
            color: #34d399;
            margin: 0.35rem 0;
        }

        .result-hero .r-sub {
            font-size: 0.9rem;
            color: #cbd5e1;
        }

        .result-lines {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .rline {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            padding: 0.9rem 1rem;
        }

        .rline .l {
            font-size: 0.75rem;
            color: #94a3b8;
        }

        .rline .v {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-top: 0.2rem;
        }

        table.schedule {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        table.schedule th,
        table.schedule td {
            padding: 0.55rem 0.6rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            text-align: right;
        }

        table.schedule th {
            color: #94a3b8;
            font-weight: 500;
            text-align: right;
        }

        table.schedule th:first-child,
        table.schedule td:first-child {
            text-align: left;
        }

        .cta-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .cta-row .btn {
            flex: 1;
            min-width: 200px;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }

        .btn-partner {
            background: linear-gradient(90deg, #10b981, #059669);
            color: #fff;
        }

        .btn-partner:hover {
            filter: brightness(1.08);
        }

        .btn-pdf {
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.35) !important;
            color: #38bdf8;
        }

        .btn-pdf:hover {
            background: rgba(56, 189, 248, 0.25);
        }

        /* Selling points + QR */
        .selling {
            margin-top: 2.5rem;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 860px) {
            .selling {
                grid-template-columns: 1fr;
            }
        }

        .points {
            display: grid;
            gap: 1rem;
        }

        .point {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .point i {
            color: #10b981;
            font-size: 1.3rem;
            margin-top: 0.15rem;
        }

        .point .p-t {
            font-weight: 700;
            color: #f1f5f9;
        }

        .point .p-d {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .qr-card {
            text-align: center;
        }

        .qr-card #qrcode {
            display: inline-block;
            background: #fff;
            padding: 12px;
            border-radius: 12px;
        }

        .qr-card .qr-note {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 0.75rem;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            width: 440px;
            max-width: 100%;
        }

        .modal-box h3 {
            margin: 0 0 0.5rem;
        }

        .modal-box p.sub {
            color: #94a3b8;
            font-size: 0.9rem;
            margin: 0 0 1.25rem;
        }
    </style>
</head>

<body>
    <div class="sim-wrap">
        <div class="sim-topbar">
            <div class="brand-logos">
                <img src="logo.png" alt="Cattle Invest" class="cattle">
                <?php if ($rialmaLogoExists): ?>
                    <img src="assets/rialma.png" alt="Rialma Agropecuária" class="rialma-logo-img">
                <?php else: ?>
                    <div class="rialma-wordmark">
                        <span class="r-name">RIALMA</span>
                        <span class="r-sub">Agropecuária</span>
                    </div>
                <?php endif; ?>
            </div>
            <a href="login.php" class="btn-back"><i class="fas fa-arrow-left"></i> Voltar ao login</a>
        </div>

        <div class="hero">
            <h1>Simule sua Parceria de Engorda</h1>
            <p>Descubra em segundos quanto você recebe pela sua boiada. A <strong>Rialma garante a parceria</strong>,
                paga <strong>à vista em até 24h</strong> e você gira seu capital mais vezes por ano.</p>
        </div>

        <div class="sim-grid">
            <!-- Inputs -->
            <div class="panel">
                <h2><i class="fas fa-sliders-h" style="color:#38bdf8;"></i> Dados da Boiada</h2>

                <div class="field">
                    <label>Número de bois</label>
                    <div class="input-wrap">
                        <input type="number" id="animals" min="1" step="1" value="100" oninput="recalc()">
                    </div>
                </div>

                <div class="field">
                    <label>Peso médio de entrada por animal (kg)</label>
                    <div class="input-wrap">
                        <input type="number" id="weight" min="1" step="1" value="450" oninput="recalc()">
                    </div>
                </div>

                <div class="field">
                    <label>Preço da arroba (@ = 30 kg)</label>
                    <div class="input-wrap">
                        <input type="number" id="price" min="1" step="0.01" value="315.00" data-currency oninput="markPriceManual(); recalc()">
                    </div>
                    <div class="price-status" id="priceStatus">Buscando cotação do boi gordo...</div>
                </div>

                <div class="field">
                    <label>Prazo de participação</label>
                    <div class="days-chips">
                        <?php foreach ($rateTable as $tier): ?>
                            <div class="day-chip" data-days="<?= $tier['days'] ?>" onclick="setDays(<?= $tier['days'] ?>)">
                                <div class="d-days"><?= $tier['days'] ?> dias</div>
                                <div class="d-rate"><?= number_format($tier['rate'], 1, ',', '.') ?>% a.m.</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="input-wrap">
                        <input type="number" id="days" min="1" step="1" value="90" oninput="recalc()">
                    </div>
                    <div class="price-status" id="rateNote"></div>
                </div>
            </div>

            <!-- Results -->
            <div class="panel">
                <h2><i class="fas fa-chart-line" style="color:#10b981;"></i> Resultado da Simulação</h2>

                <div class="result-hero">
                    <div class="r-label">Valor total a receber</div>
                    <div class="r-total" id="rTotal">R$ 0,00</div>
                    <div class="r-sub" id="rSub">—</div>
                </div>

                <div class="result-lines">
                    <div class="rline">
                        <div class="l">Valor base da boiada</div>
                        <div class="v" id="rBase">R$ 0,00</div>
                    </div>
                    <div class="rline">
                        <div class="l">Participação total</div>
                        <div class="v" id="rPart" style="color:#34d399;">R$ 0,00</div>
                    </div>
                    <div class="rline">
                        <div class="l">Custo médio por mês</div>
                        <div class="v" id="rMonthly">R$ 0,00</div>
                    </div>
                    <div class="rline">
                        <div class="l">Total de arrobas</div>
                        <div class="v" id="rArrobas">0 @</div>
                    </div>
                </div>

                <table class="schedule">
                    <thead>
                        <tr>
                            <th>Mês</th>
                            <th>Participação</th>
                            <th>Acumulado</th>
                        </tr>
                    </thead>
                    <tbody id="scheduleBody"></tbody>
                </table>

                <div class="cta-row">
                    <button class="btn btn-partner" onclick="openPartnerModal()">
                        <i class="fas fa-handshake"></i> Quero ser Parceiro
                    </button>
                    <a class="btn btn-pdf" id="pdfBtn" href="#" target="_blank">
                        <i class="fas fa-file-pdf"></i> Gerar PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Selling points + QR -->
        <div class="selling">
            <div class="panel points">
                <div class="point">
                    <i class="fas fa-bolt"></i>
                    <div>
                        <div class="p-t">Receba à vista em até 24 horas</div>
                        <div class="p-d">Você não espera o fim do ciclo: recebe o valor da parceria imediatamente e já
                            compra mais gado.</div>
                    </div>
                </div>
                <div class="point">
                    <i class="fas fa-rotate"></i>
                    <div>
                        <div class="p-t">Mais giros por ano</div>
                        <div class="p-d">Cada ciclo de engorda dura de 90 a 120 dias. Reinvestindo, você faz 3 a 4 giros
                            por ano — mais boiadas, mais lucro.</div>
                    </div>
                </div>
                <div class="point">
                    <i class="fas fa-shield-halved"></i>
                    <div>
                        <div class="p-t">Garantia Rialma</div>
                        <div class="p-d">A Rialma garante a parceria do início ao fim e incentiva prazos menores para você
                            fazer mais negócios.</div>
                    </div>
                </div>
                <div class="point">
                    <i class="fas fa-clock"></i>
                    <div>
                        <div class="p-t">Quanto menor o prazo, maior a taxa</div>
                        <div class="p-d">2,9% a.m. em 30 dias, 2,8% em 60, 2,7% em 90 e 2,6% em 120 dias.</div>
                    </div>
                </div>
            </div>
            <div class="panel qr-card">
                <h2 style="justify-content:center;"><i class="fas fa-qrcode" style="color:#38bdf8;"></i> Compartilhe</h2>
                <div id="qrcode"></div>
                <div class="qr-note">Aponte a câmera para abrir a simulação em qualquer celular.</div>
            </div>
        </div>
    </div>

    <!-- Partner Modal -->
    <div class="modal-overlay" id="partnerModal">
        <div class="modal-box">
            <h3>Quero ser Parceiro</h3>
            <p class="sub">Deixe seu contato (opcional) e siga para o cadastro. Sua simulação será anexada
                automaticamente ao seu cadastro.</p>
            <div class="field">
                <label>Nome</label>
                <input type="text" id="cName" placeholder="Seu nome">
            </div>
            <div class="field">
                <label>WhatsApp / Telefone</label>
                <input type="text" id="cPhone" placeholder="(00) 00000-0000">
            </div>
            <div class="field">
                <label>E-mail</label>
                <input type="email" id="cEmail" placeholder="seu@email.com">
            </div>
            <div class="cta-row" style="margin-top:0.5rem;">
                <button class="btn btn-pdf" onclick="closePartnerModal()"
                    style="flex:0 0 auto; min-width:120px;">Cancelar</button>
                <button class="btn btn-partner" id="goRegisterBtn" onclick="submitPartner()">
                    <i class="fas fa-arrow-right"></i> Continuar cadastro
                </button>
            </div>
        </div>
    </div>

    <script>
        const SIM_URL = <?= json_encode($simUrl) ?>;
        let priceIsManual = false;

        function money(v) {
            return (v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }

        function rateForDays(d) {
            if (d <= 30) return 2.9;
            if (d <= 60) return 2.8;
            if (d <= 90) return 2.7;
            return 2.6;
        }

        function getInputs() {
            return {
                animals: parseFloat(document.getElementById('animals').value) || 0,
                weight: parseFloat(document.getElementById('weight').value) || 0,
                price: parseFloat(document.getElementById('price').value) || 0,
                days: parseInt(document.getElementById('days').value) || 0,
            };
        }

        function calc(animals, weight, price, days) {
            const rate = rateForDays(days);
            const totalArrobas = (weight * animals) / 30;
            const base = totalArrobas * price;
            const months = days > 0 ? days / 30 : 0;
            const final = months > 0 ? base * Math.pow(1 + rate / 100, months) : base;
            const total = final - base;

            const schedule = [];
            let balance = base;
            const fullMonths = Math.floor(months);
            for (let m = 1; m <= fullMonths; m++) {
                const interest = balance * (rate / 100);
                balance += interest;
                schedule.push({ month: m, participation: interest, balance, partial: false, days: 30 });
            }
            const frac = months - fullMonths;
            if (frac > 0.0001) {
                const interest = balance * (Math.pow(1 + rate / 100, frac) - 1);
                balance += interest;
                schedule.push({ month: fullMonths + 1, participation: interest, balance, partial: true, days: Math.round(frac * 30) });
            }
            const avgMonthly = months > 0 ? total / months : 0;
            return { rate, totalArrobas, base, months, final, total, avgMonthly, schedule };
        }

        function recalc() {
            const i = getInputs();
            const r = calc(i.animals, i.weight, i.price, i.days);

            document.getElementById('rTotal').innerText = money(r.final);
            document.getElementById('rSub').innerText =
                `${i.animals} bois • ${i.days} dias • ${r.rate.toLocaleString('pt-BR', { minimumFractionDigits: 1 })}% ao mês`;
            document.getElementById('rBase').innerText = money(r.base);
            document.getElementById('rPart').innerText = money(r.total);
            document.getElementById('rMonthly').innerText = money(r.avgMonthly);
            document.getElementById('rArrobas').innerText =
                r.totalArrobas.toLocaleString('pt-BR', { maximumFractionDigits: 0 }) + ' @';

            // schedule
            const tbody = document.getElementById('scheduleBody');
            tbody.innerHTML = '';
            r.schedule.forEach(s => {
                const tr = document.createElement('tr');
                const label = s.partial ? `${s.month}º (${s.days} dias)` : `${s.month}º mês`;
                tr.innerHTML =
                    `<td>${label}</td><td>${money(s.participation)}</td><td>${money(s.balance)}</td>`;
                tbody.appendChild(tr);
            });

            // active chip
            document.querySelectorAll('.day-chip').forEach(c => {
                c.classList.toggle('active', parseInt(c.dataset.days) === i.days);
            });
            document.getElementById('rateNote').innerText =
                `Taxa aplicada: ${r.rate.toLocaleString('pt-BR', { minimumFractionDigits: 1 })}% ao mês (${i.days} dias).`;

            // PDF link
            const params = new URLSearchParams({
                animals: i.animals, weight: i.weight, price: i.price, days: i.days
            });
            document.getElementById('pdfBtn').href = 'simulacao_pdf.php?' + params.toString();
        }

        function setDays(d) {
            document.getElementById('days').value = d;
            recalc();
        }

        function markPriceManual() {
            priceIsManual = true;
            document.getElementById('priceStatus').innerText = 'Preço ajustado manualmente.';
        }

        // Fetch live boi price
        function fetchBoiPrice() {
            fetch('api_boi_price.php')
                .then(r => r.json())
                .then(data => {
                    if (priceIsManual) return;
                    if (data && data.success && data.price) {
                        document.getElementById('price').value = parseFloat(data.price).toFixed(2);
                        const status = document.getElementById('priceStatus');
                        if (data.is_fallback) {
                            status.innerText = `Cotação de referência: ${money(data.price)} — ajuste se desejar.`;
                        } else {
                            status.innerText = `Cotação (${data.source}) em ${data.fetched_at}: ${money(data.price)}. Você pode ajustar.`;
                        }
                        recalc();
                    }
                })
                .catch(() => {
                    document.getElementById('priceStatus').innerText = 'Não foi possível buscar a cotação. Ajuste o preço manualmente.';
                });
        }

        // Partner modal
        function openPartnerModal() { document.getElementById('partnerModal').classList.add('open'); }
        function closePartnerModal() { document.getElementById('partnerModal').classList.remove('open'); }

        function submitPartner() {
            const i = getInputs();
            if (i.animals <= 0 || i.weight <= 0 || i.price <= 0 || i.days <= 0) {
                alert('Preencha os dados da simulação antes de continuar.');
                return;
            }
            const btn = document.getElementById('goRegisterBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparando...';

            const fd = new FormData();
            fd.append('num_animals', i.animals);
            fd.append('entry_weight', i.weight);
            fd.append('arroba_price', i.price);
            fd.append('days', i.days);
            fd.append('client_name', document.getElementById('cName').value);
            fd.append('client_phone', document.getElementById('cPhone').value);
            fd.append('client_email', document.getElementById('cEmail').value);

            fetch('api_save_simulation.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.register_url) {
                        window.location.href = data.register_url;
                    } else {
                        alert('Erro: ' + (data.message || 'não foi possível iniciar o cadastro.'));
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continuar cadastro';
                    }
                })
                .catch(() => {
                    alert('Erro de rede. Tente novamente.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-arrow-right"></i> Continuar cadastro';
                });
        }

        // Init
        document.addEventListener('DOMContentLoaded', function () {
            new QRCode(document.getElementById('qrcode'), {
                text: SIM_URL,
                width: 150,
                height: 150,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
            });
            recalc();
            fetchBoiPrice();
        });

        // Close modal on overlay click
        document.getElementById('partnerModal').addEventListener('click', function (e) {
            if (e.target === this) closePartnerModal();
        });
    </script>
</body>

</html>
