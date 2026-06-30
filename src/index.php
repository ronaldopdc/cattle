<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once 'dashboard_stats.php';
$showSlaughterInvestor = $userRole === 'admin' && ($selectedPartnerId ?? 'all') === 'all';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cattle Invest - Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="container">

        <!-- Filter Bar -->
        <div class="card" style="margin-bottom: 5px; padding: 0.5rem 1.25rem;">
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                
                <?php if ($userRole === 'admin'): ?>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <label for="partnerFilter" style="color: #94a3b8; font-size: 0.75rem; font-weight: 500; white-space: nowrap; margin: 0;">Parceiro(a)</label>
                        <select id="partnerFilter" style="width: 200px; margin: 0;">
                            <option value="all" <?= ($selectedPartnerId ?? 'all') === 'all' ? 'selected' : '' ?>>Todos os Parceiros</option>
                            <?php foreach ($all_partners as $partner): ?>
                                <option value="<?= $partner['id'] ?>" <?= ($selectedPartnerId ?? '') == $partner['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($partner['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="display: flex; align-items: center; gap: 6px;">
                    <label style="color: #94a3b8; font-size: 0.75rem; font-weight: 500; white-space: nowrap; margin: 0;">Início</label>
                    <input type="date" id="dashDateStart" value="<?= $startDateParam ?>" style="padding: 4px 8px; border-radius: 6px; background: #1e293b; border: 1px solid #334155; color: #f8fafc; margin: 0; width: 140px;">
                </div>

                <div style="display: flex; align-items: center; gap: 6px;">
                    <label style="color: #94a3b8; font-size: 0.75rem; font-weight: 500; white-space: nowrap; margin: 0;">Fim</label>
                    <input type="date" id="dashDateEnd" value="<?= $endDateParam ?>" style="padding: 4px 8px; border-radius: 6px; background: #1e293b; border: 1px solid #334155; color: #f8fafc; margin: 0; width: 140px;">
                </div>

                <div style="display: flex; align-items: center; gap: 6px;">
                    <label style="color: #94a3b8; font-size: 0.75rem; font-weight: 500; white-space: nowrap; margin: 0;">Intervalo</label>
                    <select id="quickRange" style="width: 130px; padding: 4px 8px; border-radius: 6px; background: #1e293b; border: 1px solid #334155; color: #f8fafc; margin: 0;">
                        <option value="">Personalizado</option>
                        <option value="3">3 Meses</option>
                        <option value="6">6 Meses</option>
                        <option value="9">9 Meses</option>
                        <option value="12">12 Meses</option>
                        <option value="24">24 Meses</option>
                    </select>
                </div>

                <button class="btn btn-primary" onclick="applyDashboardFilters()" style="padding: 4px 14px; white-space: nowrap; margin: 0; height: 30px; display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem;">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
            </div>
        </div>

        <!-- Stats Widget -->
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 5px; margin-bottom: 5px;">
            <div class="card" style="text-align: center; padding: 1.25rem; margin-bottom: 0;">
                <h4 style="margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500;">Parcerias Ativas</h4>
                <p style="margin: 0.35rem 0 0; font-size: 1.4rem; font-weight: 700; color: #f8fafc;">
                    <?= $total_active_partnerships ?>
                </p>
            </div>
            <div class="card" style="text-align: center; padding: 1.25rem; margin-bottom: 0;">
                <h4 style="margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500;">Valor Total Investido</h4>
                <p style="margin: 0.35rem 0 0; font-size: 1.4rem; font-weight: 700; color: #f8fafc;">R$
                    <?= number_format($total_invested, 2, ',', '.') ?>
                </p>
            </div>
            <div class="card" style="text-align: center; padding: 1.25rem; margin-bottom: 0;">
                <h4 style="margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500;">Saldo Atual</h4>
                <p style="margin: 0.35rem 0 0; font-size: 1.4rem; font-weight: 700; color: #818cf8;">R$
                    <?= number_format($total_current_balance, 2, ',', '.') ?>
                </p>
            </div>
            <div class="card" style="text-align: center; padding: 1.25rem; margin-bottom: 0;">
                <h4 style="margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500;">Saldo Previsto</h4>
                <p style="margin: 0.35rem 0 0; font-size: 1.4rem; font-weight: 700; color: #34d399;">R$
                    <?= number_format($total_projected_balance, 2, ',', '.') ?>
                </p>
            </div>
            <div class="card" style="text-align: center; padding: 1.25rem; margin-bottom: 0;">
                <h4 style="margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500;">Rendimento Total Previsto</h4>
                <p style="margin: 0.35rem 0 0; font-size: 1.4rem; font-weight: 700; color: #fbbf24;">R$
                    <?= number_format($total_yield, 2, ',', '.') ?>
                </p>
            </div>
            <div class="card" style="text-align: center; padding: 1.25rem; margin-bottom: 0;">
                <h4 style="margin: 0; color: #94a3b8; font-size: 0.85rem; font-weight: 500;">Rendimento Total</h4>
                <p style="margin: 0.35rem 0 0; font-size: 1.4rem; font-weight: 700; color: #10b981;">R$
                    <?= number_format($total_settled_period_yield, 2, ',', '.') ?>
                </p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid"
            style="grid-template-columns: minmax(0, 0.6fr) minmax(0, 1.4fr); gap: 5px; margin-bottom: 5px; align-items: stretch; width: 100%; min-width: 0;">
            <div class="card" style="padding: 1.25rem 1.25rem 4rem 1.25rem; display: flex; flex-direction: column; height: 520px; margin-bottom: 0; min-width: 0; overflow: hidden;">
                <h4 style="margin: 0 0 1rem; color: #94a3b8; font-size: 0.9rem; font-weight: 500;">Distribuição por
                    Proprietário</h4>
                <div style="flex-grow: 1; min-height: 0; min-width: 0; position: relative; width: 100%;">
                    <canvas id="valueByOwnerChart"></canvas>
                </div>
            </div>
            <div class="card" style="padding: 1.25rem; display: flex; flex-direction: column; height: 520px; margin-bottom: 0; min-width: 0; overflow: hidden;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; min-width: 0; gap: 0.75rem;">
                    <h4 style="margin: 0; color: #94a3b8; font-size: 0.9rem; font-weight: 500; min-width: 0; overflow-wrap: anywhere;">Rendimento Mensal (-6m / +6m)</h4>
                    <button onclick="openYieldReportModal()" class="btn" style="padding: 4px 12px; font-size: 0.8rem; background: rgba(96, 165, 250, 0.1); border: 1px solid rgba(96, 165, 250, 0.2); color: #60a5fa;">
                        <i class="fas fa-file-alt"></i> Relatório
                    </button>
                </div>
                <div style="flex-grow: 1; min-height: 0; min-width: 0; position: relative; width: 100%;">
                    <canvas id="valueHistoryChart"></canvas>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 5px;">
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0; color: #fbbf24;"><i class="fas fa-calendar-alt"></i> Próximos Abates</h3>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <label style="margin: 0; font-size: 0.75rem; color: #94a3b8;">Ver:</label>
                        <select onchange="updateTableLimit('upcoming', this.value)" style="width: 70px; padding: 2px 5px; margin: 0; font-size: 0.8rem; background: #1e293b; border: 1px solid #334155; color: #f8fafc; border-radius: 4px;">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                        <button onclick="openUpcomingSlaughterReportModal()" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.2); color: #fbbf24;">
                            <i class="fas fa-file-alt"></i> Relat&oacute;rio
                        </button>
                    </div>
                </div>
                <div id="upcoming-container" style="overflow-x: auto; overflow-y: hidden; transition: all 0.3s ease;">
                    <table style="width: 100%; font-size: 0.9rem; margin-top: 0;">
                        <thead>
                            <tr style="text-align: left; color: #94a3b8; border-bottom: 1px solid rgba(255,255,255,0.1);">
                                <th style="padding: 0.5rem;">Data</th>
                                <th style="padding: 0.5rem;">Lote</th>
                                <th style="padding: 0.5rem;">Parceria #</th>
                                <th style="padding: 0.5rem;">Proprietário</th>
                                <?php if ($showSlaughterInvestor): ?>
                                    <th style="padding: 0.5rem;">Investidor</th>
                                <?php endif; ?>
                                <th style="padding: 0.5rem; text-align: right;">Valor Atual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_lots as $lot): ?>
                                <tr class="upcoming-row" style="border-bottom: 1px solid rgba(255,255,255,0.05); display: none;">
                                    <td style="padding: 0.75rem 0.5rem;"><?= date('d/m/Y', strtotime($lot['slaughter_date'])) ?></td>
                                    <td style="padding: 0.75rem 0.5rem;"><?= htmlspecialchars($lot['lot_numbers']) ?></td>
                                    <td style="padding: 0.75rem 0.5rem;">#<?= $lot['partnership_id'] ?></td>
                                    <td style="padding: 0.75rem 0.5rem;"><?= htmlspecialchars($lot['owner_name']) ?></td>
                                    <?php if ($showSlaughterInvestor): ?>
                                        <td style="padding: 0.75rem 0.5rem;"><?= htmlspecialchars($lot['investor_name']) ?></td>
                                    <?php endif; ?>
                                    <td style="padding: 0.75rem 0.5rem; text-align: right; color: #818cf8; font-weight: 600;">R$ <?= number_format($lot['current_balance'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($upcoming_lots)): ?>
                                <tr><td colspan="<?= $showSlaughterInvestor ? 6 : 5 ?>" style="padding: 1rem; text-align: center; color: #64748b;">Nenhuma liquidação/abate futuro.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0; color: #34d399;"><i class="fas fa-history"></i> Últimos Abates</h3>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <label style="margin: 0; font-size: 0.75rem; color: #94a3b8;">Ver:</label>
                        <select onchange="updateTableLimit('recent', this.value)" style="width: 70px; padding: 2px 5px; margin: 0; font-size: 0.8rem; background: #1e293b; border: 1px solid #334155; color: #f8fafc; border-radius: 4px;">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                        <button onclick="openCashFlowModal()" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(52, 211, 153, 0.1); border: 1px solid rgba(52, 211, 153, 0.2); color: #34d399;">
                            <i class="fas fa-book"></i> Livro Caixa
                        </button>
                        <button onclick="openLiquidationsReportModal()" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(96, 165, 250, 0.1); border: 1px solid rgba(96, 165, 250, 0.2); color: #60a5fa;">
                            <i class="fas fa-file-invoice-dollar"></i> Liquida&ccedil;&otilde;es
                        </button>
                    </div>
                </div>
                <div id="recent-container" style="overflow-x: auto; overflow-y: hidden; transition: all 0.3s ease;">
                    <table style="width: 100%; font-size: 0.9rem; margin-top: 0;">
                        <thead>
                            <tr style="text-align: left; color: #94a3b8; border-bottom: 1px solid rgba(255,255,255,0.1);">
                                <th style="padding: 0.5rem;">Data</th>
                                <th style="padding: 0.5rem;">Lote</th>
                                <th style="padding: 0.5rem;">Parceria #</th>
                                <th style="padding: 0.5rem;">Proprietário</th>
                                <?php if ($showSlaughterInvestor): ?>
                                    <th style="padding: 0.5rem;">Investidor</th>
                                <?php endif; ?>
                                <th style="padding: 0.5rem; text-align: right;">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_liquidations_list as $liq): ?>
                                <tr class="recent-row" style="border-bottom: 1px solid rgba(255,255,255,0.05); display: none;">
                                    <td style="padding: 0.75rem 0.5rem;"><?= date('d/m/Y', strtotime($liq['date'])) ?></td>
                                    <td style="padding: 0.75rem 0.5rem;"><?= htmlspecialchars($liq['lot_numbers']) ?></td>
                                    <td style="padding: 0.75rem 0.5rem;">#<?= $liq['partnership_id'] ?></td>
                                    <td style="padding: 0.75rem 0.5rem;"><?= htmlspecialchars($liq['owner_name']) ?></td>
                                    <?php if ($showSlaughterInvestor): ?>
                                        <td style="padding: 0.75rem 0.5rem;"><?= htmlspecialchars($liq['investor_name']) ?></td>
                                    <?php endif; ?>
                                    <td style="padding: 0.75rem 0.5rem; text-align: right; color: #10b981; font-weight: 600;">R$ <?= number_format($liq['amount_total'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent_liquidations_list)): ?>
                                <tr><td colspan="<?= $showSlaughterInvestor ? 6 : 5 ?>" style="padding: 1rem; text-align: center; color: #64748b;">Nenhuma liquidação/abate realizado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            $('#partnerFilter').select2({
                placeholder: 'Selecione um parceiro',
                allowClear: false
            });

            $('#partnerFilter').on('change', function() {
                applyDashboardFilters();
            });

            $('#quickRange').on('change', function() {
                const months = parseInt($(this).val());
                if (!months) return;

                const now = new Date();
                const baseDate = new Date(now.getFullYear(), now.getMonth(), 1);
                
                // Logic: floor(N/2) months before, current month, N - floor(N/2) - 1 months after
                const monthsBefore = Math.floor(months / 2);
                const monthsAfter = months - monthsBefore - 1;

                const startDt = new Date(baseDate);
                startDt.setMonth(startDt.getMonth() - monthsBefore);
                
                const endDt = new Date(baseDate);
                endDt.setMonth(endDt.getMonth() + monthsAfter + 1); // 1st of month after the end
                endDt.setDate(0); // Last day of previous month

                document.getElementById('dashDateStart').value = startDt.toISOString().split('T')[0];
                document.getElementById('dashDateEnd').value = endDt.toISOString().split('T')[0];
                
                applyDashboardFilters();
            });

            // Initialize table limits
            updateTableLimit('upcoming', 5);
            updateTableLimit('recent', 5);
        });

        function updateTableLimit(type, limit) {
            limit = parseInt(limit);
            const rows = document.querySelectorAll(`.${type}-row`);
            const container = document.getElementById(`${type}-container`);
            
            rows.forEach((row, index) => {
                row.style.display = index < limit ? 'table-row' : 'none';
            });

            if (limit > 5) {
                // Approximate row height is 45px. 5 rows + header = ~270px
                container.style.maxHeight = '270px';
                container.style.overflowY = 'auto';
            } else {
                container.style.maxHeight = 'none';
                container.style.overflowY = 'hidden';
            }
        }

        function applyDashboardFilters() {
            const partnerId = $('#partnerFilter').val() || 'all';
            const startDt = document.getElementById('dashDateStart').value;
            const endDt = document.getElementById('dashDateEnd').value;
            
            const url = new URL(window.location.href);
            url.searchParams.set('partner_id', partnerId);
            url.searchParams.set('start_date', startDt);
            url.searchParams.set('end_date', endDt);
            window.location.href = url.toString();
        }

        function openCashFlowModal() {
            const modal = document.getElementById('cashFlowModal');
            document.getElementById('cfDateStart').value = document.getElementById('dashDateStart').value;
            document.getElementById('cfDateEnd').value = document.getElementById('dashDateEnd').value;
            renderCashFlowTable();
            modal.style.display = 'block';
        }

        function openYieldReportModal() {
            const modal = document.getElementById('yieldReportModal');
            if (typeof initYieldReportFilters === 'function') {
                initYieldReportFilters();
            }
            openYieldReportModalInternal();
        }

        // Pie Chart Configuration
        const pieCtx = document.getElementById('valueByOwnerChart').getContext('2d');
        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode($pie_labels) ?>,
                datasets: [{
                    data: <?= json_encode($pie_values) ?>,
                    backgroundColor: [
                        '#60a5fa', '#34d399', '#fbbf24', '#f87171', '#a78bfa',
                        '#818cf8', '#2dd4bf', '#fb923c', '#e879f9', '#94a3b8'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#e2e8f0',
                            font: { 
                                size: 13,
                                family: "'Inter', sans-serif",
                                weight: '500'
                            },
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#94a3b8',
                        bodyColor: '#f8fafc',
                        borderColor: '#334155',
                        borderWidth: 1,
                        callbacks: {
                            title: function () { return ''; },
                            label: function (context) {
                                // Since context.label now contains "Owner (R$ 0,00)", 
                                // we can just use it directly or format it.
                                return ' ' + context.label;
                            }
                        }
                    }
                },
                layout: {
                    padding: {
                        top: 0,
                        bottom: 10,
                        left: 10,
                        right: 10
                    }
                }
            }
        });

        // Line Chart Configuration
        const yieldReportData = <?= json_encode($yield_report_data) ?>;
        
        const lineCtx = document.getElementById('valueHistoryChart').getContext('2d');
        const linePercentages = <?= json_encode($line_percentages) ?>;
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($line_labels) ?>.map((m, i) => {
                    const val = <?= json_encode($line_values) ?>[i];
                    const pct = linePercentages[i];
                    return [m, 'R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }), pct.toLocaleString('pt-BR') + '%'];
                }),
                datasets: [{
                    label: 'Rendimento (R$)',
                    data: <?= json_encode($line_values) ?>,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52, 211, 153, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#34d399'
                }, {
                    label: 'R$ <?= number_format($average_yield, 0, ',', '.') ?> (<?= number_format($average_percentage, 2, ',', '.') ?>%)',
                    data: Array(<?= count($line_values) ?>).fill(<?= $average_yield ?>),
                    borderColor: '#f87171',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                    order: 1
                },
                {
                    label: 'Valor Investido',
                    data: <?= json_encode($monthly_invested_values) ?>,
                    borderColor: '#f8fafc',
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    fill: false,
                    pointRadius: 3,
                    yAxisID: 'y1',
                    order: 3
                },
                {
                    label: 'Média Inv.: R$ <?= number_format($average_invested_value, 0, ',', '.') ?>',
                    data: Array(<?= count($line_values) ?>).fill(<?= $average_invested_value ?>),
                    borderColor: '#f8fafc',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                    yAxisID: 'y1',
                    order: 2
                },
                {
                    label: 'Exp. Máx.: R$ <?= number_format($max_invested_value, 0, ',', '.') ?>',
                    data: Array(<?= count($line_values) ?>).fill(<?= $max_invested_value ?>),
                    borderColor: '#818cf8',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                    yAxisID: 'y1',
                    order: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#94a3b8',
                        bodyColor: '#f8fafc',
                        borderColor: '#334155',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                let val = context.parsed.y;
                                if (context.datasetIndex === 0) {
                                    let pct = linePercentages[context.dataIndex];
                                    return ' Rendimento: R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + ' (' + pct.toLocaleString('pt-BR') + '%)';
                                }
                                return ' ' + context.dataset.label.split(':')[0] + ': R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        grid: { color: 'rgba(51, 65, 85, 0.5)' },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 12, weight: '500' },
                            callback: function (value) {
                                return 'R$ ' + value.toLocaleString('pt-BR');
                            }
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: false,
                        grid: { display: false },
                        ticks: {
                            color: '#f8fafc',
                            font: { size: 12, weight: '500' },
                            callback: function (value) {
                                return 'R$ ' + value.toLocaleString('pt-BR');
                            }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#94a3b8',
                            font: { size: 12, weight: '500' },
                            autoSkip: false
                        }
                    }
                },
                layout: { padding: { top: 30, bottom: 10, left: 10, right: 10 } }
            },
            plugins: [{
                id: 'averageLabel',
                afterDraw: (chart) => {
                    const { ctx, chartArea: { left, right }, scales: { y, y1 } } = chart;
                    
                    if (y) {
                        const yPos = y.getPixelForValue(<?= $average_yield ?>);
                        if (yPos >= chart.chartArea.top && yPos <= chart.chartArea.bottom) {
                            ctx.save();
                            ctx.fillStyle = '#f87171';
                            ctx.font = 'bold 12px Inter, sans-serif';
                            ctx.textAlign = 'left';
                            ctx.textBaseline = 'bottom';
                            
                            const label = 'R$ <?= number_format($average_yield, 0, ',', '.') ?> (<?= number_format($average_percentage, 2, ',', '.') ?>%)';
                            ctx.fillText(label, left + 5, yPos - 5);
                            ctx.restore();
                        }
                    }
                    
                    if (y1) {
                        const y1Pos = y1.getPixelForValue(<?= $average_invested_value ?>);
                        if (y1Pos >= chart.chartArea.top && y1Pos <= chart.chartArea.bottom) {
                            ctx.save();
                            ctx.fillStyle = '#f8fafc';
                            ctx.font = 'bold 12px Inter, sans-serif';
                            ctx.textAlign = 'right';
                            ctx.textBaseline = 'bottom';
                            
                            const label1 = 'Média Inv.: R$ <?= number_format($average_invested_value, 0, ',', '.') ?>';
                            ctx.fillText(label1, right - 5, y1Pos - 5);
                            ctx.restore();
                        }
                        
                        const y1MaxPos = y1.getPixelForValue(<?= $max_invested_value ?>);
                        if (y1MaxPos >= chart.chartArea.top && y1MaxPos <= chart.chartArea.bottom) {
                            ctx.save();
                            ctx.fillStyle = '#818cf8';
                            ctx.font = 'bold 12px Inter, sans-serif';
                            ctx.textAlign = 'right';
                            ctx.textBaseline = 'bottom';
                            
                            const labelMax = 'Exp. Máx.: R$ <?= number_format($max_invested_value, 0, ',', '.') ?>';
                            ctx.fillText(labelMax, right - 5, y1MaxPos - 5);
                            ctx.restore();
                        }
                    }
                }
            }]
        });

        // Initialize Cash Flow Data
        const tblCashFlowData = <?= json_encode($cash_flow_data) ?>;
        const tblLiquidationReportData = <?= json_encode($liquidation_report_data) ?>;
        const tblUpcomingSlaughterReportData = <?= json_encode($upcoming_slaughter_report_data) ?>;
    </script>
    <?php include 'yield_report_modal.html'; ?>
    <?php include 'cash_flow_report_modal.html'; ?>
    <?php include 'liquidations_report_modal.html'; ?>
    <?php include 'upcoming_slaughter_report_modal.html'; ?>
</body>

</html>
