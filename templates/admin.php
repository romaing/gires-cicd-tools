<?php
/** @var array $settings */
/** @var array $sets */
/** @var array $files */
/** @var array $applied */
/** @var array $pending */
/** @var array $all_tables */
$is_gires_table = function ($table) {
    return strpos($table, 'gires_') === 0;
};
?>
<div class="wrap">
    <?php
    $tab = sanitize_text_field($_GET['tab'] ?? 'sets');
    $tabs = ['sets', 'migrations', 'config'];
    if (!in_array($tab, $tabs, true)) {
        $tab = 'sets';
    }
    ?>
    <h1>CI/CD Tools</h1>
    <?php if ($tab === 'config') : ?>
        <div id="gires-conn-card" style="display:inline-flex; align-items:center; gap:12px; padding:14px 18px; background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.08); margin:10px 0 6px;">
            <div id="gires-conn-icon" style="width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:26px; color:#fff; background:linear-gradient(180deg,#9aa0a6,#7d8288);">?</div>
            <div>
                <div id="gires-conn-title" style="font-weight:600; letter-spacing:1px; color:#5f6368;">CONNEXION</div>
                <div id="gires-conn-status" style="font-size:12px; color:#9aa0a6;">En attente</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['saved']) && empty($_GET['connection'])) : ?>
        <div class="notice notice-success"><p>Configuration sauvegardée.</p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['ran'])) : ?>
        <div class="notice notice-success"><p>Migrations exécutées.</p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])) : ?>
        <div class="notice notice-error"><p>Erreur lors de l'exécution des migrations.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['connection']) && (!isset($tab) || $tab !== 'config')) : ?>
        <?php if ($_GET['connection'] === '1') : ?>
            <div class="notice notice-success"><p>Connexion OK.</p></div>
        <?php else : ?>
            <div class="notice notice-error"><p>Connexion impossible.</p></div>
        <?php endif; ?>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=gires-cicd-tools&tab=sets')); ?>" class="nav-tab <?php echo $tab === 'sets' ? 'nav-tab-active' : ''; ?>">Réplications</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gires-cicd-tools&tab=migrations')); ?>" class="nav-tab <?php echo $tab === 'migrations' ? 'nav-tab-active' : ''; ?>">Migrations</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gires-cicd-tools&tab=config')); ?>" class="nav-tab <?php echo $tab === 'config' ? 'nav-tab-active' : ''; ?>">Configuration</a>
    </h2>

    <?php if ($tab === 'sets') : ?>
        <h2>Sets de réplication</h2>
        <p class="description">Chaque set définit un flux PULL ou PUSH avec tables sélectionnées, search/replace et médias.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="gires_cicd_save">
            <?php wp_nonce_field('gires_cicd_save'); ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Type</th>
                        <th>ID</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($sets ?? []) as $index => $set) : ?>
                        <tr>
                            <td><?php echo esc_html($set['name'] ?? ''); ?></td>
                            <td><?php echo esc_html(strtoupper($set['type'] ?? 'pull')); ?></td>
                            <td><code><?php echo esc_html($set['id'] ?? ''); ?></code></td>
                            <td>
                                <button type="button" class="button button-secondary gires-open-detail" data-set-index="<?php echo (int) $index; ?>">Détails</button>
                                <button type="button" class="button button-primary gires-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Lancer</button>
                                <button type="button" class="button button-secondary gires-dry-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Dry‑run</button>
                                <button type="button" class="button gires-clean" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Nettoyer</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="gires-sets" style="display:none;">
                <?php foreach (($sets ?? []) as $index => $set) : ?>
                    <?php
                    $set_tables = $set['tables'] ?? [];
                    $use_all = empty($set_tables);
                    $default_selected = array_filter($all_tables, function ($t) use ($is_gires_table) {
                        return !$is_gires_table($t);
                    });
                    $selected_tables = $use_all ? $default_selected : $set_tables;
                    $search = $set['search'] ?? [];
                    $replace = $set['replace'] ?? [];
                    if (!is_array($search)) {
                        $search = preg_split('/\r?\n/', (string) $search);
                    }
                    if (!is_array($replace)) {
                        $replace = preg_split('/\r?\n/', (string) $replace);
                    }
                    $rows = max(1, max(count($search), count($replace)));
                    ?>
                    <div class="postbox gires-set" data-index="<?php echo (int) $index; ?>" style="margin-bottom:16px;">
                        <h3 class="hndle" style="padding:8px 12px;"><?php echo esc_html($set['name'] ?: ('set_' . $index)); ?></h3>
                        <div class="inside">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">ID</th>
                                    <td><input type="text" name="replication_sets[<?php echo $index; ?>][id]" value="<?php echo esc_attr($set['id'] ?? ''); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th scope="row">Nom</th>
                                    <td><input type="text" name="replication_sets[<?php echo $index; ?>][name]" value="<?php echo esc_attr($set['name'] ?? ''); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th scope="row">Type</th>
                                    <td>
                                        <select name="replication_sets[<?php echo $index; ?>][type]">
                                            <option value="pull" <?php selected($set['type'] ?? '', 'pull'); ?>>PULL</option>
                                            <option value="push" <?php selected($set['type'] ?? '', 'push'); ?>>PUSH</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Search/Replace</th>
                                    <td>
                                        <div class="gires-search-replace" data-index="<?php echo (int) $index; ?>">
                                            <?php for ($i = 0; $i < $rows; $i++) : ?>
                                                <div class="gires-sr-row">
                                                    <input type="text" name="replication_sets[<?php echo $index; ?>][search][]" value="<?php echo esc_attr($search[$i] ?? ''); ?>" placeholder="Search" class="regular-text">
                                                    <input type="text" name="replication_sets[<?php echo $index; ?>][replace][]" value="<?php echo esc_attr($replace[$i] ?? ''); ?>" placeholder="Replace" class="regular-text">
                                                    <button type="button" class="button button-secondary gires-sr-remove">Retirer</button>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <p><button type="button" class="button button-secondary gires-sr-add" data-index="<?php echo (int) $index; ?>">Ajouter une ligne</button></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Médias</th>
                                    <td>
                                        <label><input type="checkbox" name="replication_sets[<?php echo $index; ?>][include_media]" <?php checked(!empty($set['include_media'])); ?>> Récupérer médias</label>
                                        <p class="description">Transfert par ZIP (multi-part si gros).</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Taille ZIP (Mo)</th>
                                    <td>
                                        <?php $chunk = (int) ($set['media_chunk_mb'] ?? 512); ?>
                                        <select name="replication_sets[<?php echo $index; ?>][media_chunk_mb]">
                                            <?php foreach ([8,16,32,64,128,256,512,1024,2048] as $opt) : ?>
                                                <option value="<?php echo $opt; ?>" <?php selected($chunk, $opt); ?>><?php echo $opt; ?> Mo</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Préfixe temp</th>
                                    <td><input type="text" name="replication_sets[<?php echo $index; ?>][temp_prefix]" value="<?php echo esc_attr($set['temp_prefix'] ?? 'tmp_'); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th scope="row">Préfixe backup</th>
                                    <td><input type="text" name="replication_sets[<?php echo $index; ?>][backup_prefix]" value="<?php echo esc_attr($set['backup_prefix'] ?? 'bak_'); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th scope="row">Nettoyage auto</th>
                                    <td><label><input type="checkbox" name="replication_sets[<?php echo $index; ?>][auto_cleanup]" <?php checked(!empty($set['auto_cleanup'])); ?>> Actif</label></td>
                                </tr>
                                <tr>
                                    <th scope="row">Tables</th>
                                    <td>
                                        <div class="gires-tables">
                                            <?php foreach ($all_tables as $table) : ?>
                                                <?php if ($is_gires_table($table)) : continue; endif; ?>
                                                <?php $checked = in_array($table, $selected_tables, true); ?>
                                                <label style="display:block;">
                                                    <input type="checkbox" name="replication_sets[<?php echo $index; ?>][tables][]" value="<?php echo esc_attr($table); ?>" <?php checked($checked); ?>>
                                                    <?php echo esc_html($table); ?>
                                                    <button type="button" class="button button-small gires-table-info" data-table="<?php echo esc_attr($table); ?>" style="margin-left:6px;">Schema</button>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="description">Par défaut: toutes les tables sauf <code>gires_%</code> (cachées de la liste).</p>
                                    </td>
                                </tr>
                            </table>

                            <div class="gires-actions">
                                <button type="button" class="button button-primary gires-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Lancer</button>
                                <button type="button" class="button button-secondary gires-dry-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Dry‑run</button>
                                <button type="button" class="button gires-clean" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Nettoyer</button>
                                <span class="gires-status" style="margin-left:10px;"></span>
                                <div class="gires-progress" style="margin-top:8px; max-width:420px;">
                                    <div class="gires-progress-bar" style="height:8px; background:#2271b1; width:0%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary">Sauvegarder les sets</button>
                <button type="button" class="button button-secondary" id="gires-add-set">+ Ajouter un set</button>
            </p>

            <div id="gires-detail-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999;">
                <div style="background:#fff; max-width:1000px; margin:4vh auto; padding:16px; border-radius:6px; max-height:88vh; overflow:auto;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h3 style="margin:0;">Détails du set</h3>
                        <button type="button" class="button" id="gires-detail-close">Fermer</button>
                    </div>
                    <div id="gires-detail-content" style="margin-top:12px;"></div>
                </div>
            </div>
        </form>

        <script type="text/template" id="gires-set-template">
            <div class="postbox gires-set" data-index="__INDEX__" style="margin-bottom:16px;">
                <h3 class="hndle" style="padding:8px 12px;">Nouveau set</h3>
                <div class="inside">
                    <table class="form-table">
                        <tr><th scope="row">ID</th><td><input type="text" name="replication_sets[__INDEX__][id]" class="regular-text"></td></tr>
                        <tr><th scope="row">Nom</th><td><input type="text" name="replication_sets[__INDEX__][name]" class="regular-text"></td></tr>
                        <tr><th scope="row">Type</th><td><select name="replication_sets[__INDEX__][type]"><option value="pull">PULL</option><option value="push">PUSH</option></select></td></tr>
                        <tr><th scope="row">Search/Replace</th><td>
                            <div class="gires-search-replace" data-index="__INDEX__">
                                <div class="gires-sr-row">
                                    <input type="text" name="replication_sets[__INDEX__][search][]" placeholder="Search" class="regular-text">
                                    <input type="text" name="replication_sets[__INDEX__][replace][]" placeholder="Replace" class="regular-text">
                                    <button type="button" class="button button-secondary gires-sr-remove">Retirer</button>
                                </div>
                            </div>
                            <p><button type="button" class="button button-secondary gires-sr-add" data-index="__INDEX__">Ajouter une ligne</button></p>
                        </td></tr>
                        <tr><th scope="row">Médias</th><td><label><input type="checkbox" name="replication_sets[__INDEX__][include_media]" checked> Récupérer médias</label></td></tr>
                        <tr><th scope="row">Taille ZIP (Mo)</th><td>
                            <select name="replication_sets[__INDEX__][media_chunk_mb]">
                                <option value="8">8 Mo</option>
                                <option value="16">16 Mo</option>
                                <option value="32">32 Mo</option>
                                <option value="64">64 Mo</option>
                                <option value="128">128 Mo</option>
                                <option value="256">256 Mo</option>
                                <option value="512" selected>512 Mo</option>
                                <option value="1024">1024 Mo</option>
                                <option value="2048">2048 Mo</option>
                            </select>
                        </td></tr>
                        <tr><th scope="row">Préfixe temp</th><td><input type="text" name="replication_sets[__INDEX__][temp_prefix]" value="tmp_" class="regular-text"></td></tr>
                        <tr><th scope="row">Préfixe backup</th><td><input type="text" name="replication_sets[__INDEX__][backup_prefix]" value="bak_" class="regular-text"></td></tr>
                        <tr><th scope="row">Nettoyage auto</th><td><label><input type="checkbox" name="replication_sets[__INDEX__][auto_cleanup]" checked> Actif</label></td></tr>
                        <tr><th scope="row">Tables</th><td>
                            <?php foreach ($all_tables as $table) : ?>
                                <?php if ($is_gires_table($table)) : continue; endif; ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="replication_sets[__INDEX__][tables][]" value="<?php echo esc_attr($table); ?>" <?php echo $is_gires_table($table) ? '' : 'checked'; ?>>
                                    <?php echo esc_html($table); ?>
                                    <button type="button" class="button button-small gires-table-info" data-table="<?php echo esc_attr($table); ?>" style="margin-left:6px;">Schema</button>
                                </label>
                            <?php endforeach; ?>
                        </td></tr>
                    </table>
                    <div class="gires-actions">
                        <button type="button" class="button button-primary gires-run" data-set-id="">Lancer</button>
                        <button type="button" class="button button-secondary gires-dry-run" data-set-id="">Dry‑run</button>
                        <button type="button" class="button gires-clean" data-set-id="">Nettoyer</button>
                        <span class="gires-status" style="margin-left:10px;"></span>
                        <div class="gires-progress" style="margin-top:8px; max-width:420px;">
                            <div class="gires-progress-bar" style="height:8px; background:#2271b1; width:0%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </script>

        <div id="gires-table-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999;">
            <div style="background:#fff; max-width:900px; margin:6vh auto; padding:16px; border-radius:6px; max-height:80vh; overflow:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Table</h3>
                    <button type="button" class="button" id="gires-table-close">Fermer</button>
                </div>
                <div style="margin-top:12px;">
                    <h4>Schema</h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Type</th>
                                <th>Null</th>
                                <th>Key</th>
                                <th>Default</th>
                                <th>Extra</th>
                            </tr>
                        </thead>
                        <tbody id="gires-table-schema"></tbody>
                    </table>
                </div>
                <div style="margin-top:16px;">
                    <h4>Example row</h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Column</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody id="gires-table-example"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            (function() {
                var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var nonce = '<?php echo esc_js(wp_create_nonce('gires_cicd_job')); ?>';
                var container = document.getElementById('gires-sets');
                var addBtn = document.getElementById('gires-add-set');
                var template = document.getElementById('gires-set-template');
                var modal = document.getElementById('gires-table-modal');
                var modalTitle = modal ? modal.querySelector('h3') : null;
                var schemaBody = document.getElementById('gires-table-schema');
                var exampleBody = document.getElementById('gires-table-example');
                var modalClose = document.getElementById('gires-table-close');
                var detailModal = document.getElementById('gires-detail-modal');
                var detailClose = document.getElementById('gires-detail-close');
                var detailContent = document.getElementById('gires-detail-content');

                if (modalClose && modal) {
                    modalClose.addEventListener('click', function() {
                        modal.style.display = 'none';
                    });
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            modal.style.display = 'none';
                        }
                    });
                }

                if (detailClose && detailModal) {
                    detailClose.addEventListener('click', function() {
                        detailModal.style.display = 'none';
                        if (detailContent) {
                            detailContent.innerHTML = '';
                        }
                    });
                    detailModal.addEventListener('click', function(e) {
                        if (e.target === detailModal) {
                            detailModal.style.display = 'none';
                            if (detailContent) {
                                detailContent.innerHTML = '';
                            }
                        }
                    });
                }

                function bindSearchReplace(box) {
                    box.querySelectorAll('.gires-sr-remove').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var row = btn.closest('.gires-sr-row');
                            if (row) row.remove();
                        });
                    });
                }

                document.querySelectorAll('.gires-search-replace').forEach(bindSearchReplace);

                document.querySelectorAll('.gires-sr-add').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var index = btn.getAttribute('data-index');
                        var box = document.querySelector('.gires-search-replace[data-index="' + index + '"]');
                        if (!box) return;
                        var row = document.createElement('div');
                        row.className = 'gires-sr-row';
                        row.innerHTML = '<input type="text" name="replication_sets[' + index + '][search][]" placeholder="Search" class="regular-text"> ' +
                            '<input type="text" name="replication_sets[' + index + '][replace][]" placeholder="Replace" class="regular-text"> ' +
                            '<button type="button" class="button button-secondary gires-sr-remove">Retirer</button>';
                        box.appendChild(row);
                        bindSearchReplace(box);
                    });
                });

                if (addBtn) {
                    addBtn.addEventListener('click', function() {
                        var index = container.querySelectorAll('.gires-set').length;
                        if (!template) return;
                        var html = template.innerHTML.replace(/__INDEX__/g, index);
                        var wrapper = document.createElement('div');
                        wrapper.innerHTML = html;
                        var div = wrapper.firstElementChild;
                        container.appendChild(div);
                        bindSearchReplace(div.querySelector('.gires-search-replace'));
                        div.querySelector('.gires-sr-add').addEventListener('click', function() {
                            var index = div.getAttribute('data-index');
                            var box = div.querySelector('.gires-search-replace');
                            var row = document.createElement('div');
                            row.className = 'gires-sr-row';
                            row.innerHTML = '<input type="text" name="replication_sets[' + index + '][search][]" placeholder="Search" class="regular-text"> ' +
                                '<input type="text" name="replication_sets[' + index + '][replace][]" placeholder="Replace" class="regular-text"> ' +
                                '<button type="button" class="button button-secondary gires-sr-remove">Retirer</button>';
                            box.appendChild(row);
                            bindSearchReplace(box);
                        });
                        bindRun(div.querySelector('.gires-run'), false);
                        bindRun(div.querySelector('.gires-dry-run'), true);
                        bindClean(div.querySelector('.gires-clean'));
                        div.querySelectorAll('.gires-table-info').forEach(bindTableInfo);
                    });
                }

                function poll(statusEl, barEl) {
                    var form = new FormData();
                    form.append('action', 'gires_cicd_job_step');
                    form.append('_ajax_nonce', nonce);
                    fetch(ajaxUrl, { method: 'POST', body: form })
                        .then(r => r.json())
                        .then(function(data) {
                            if (!data || !data.success) {
                                statusEl.textContent = data && data.data ? data.data.message : 'Erreur';
                                return;
                            }
                            var p = data.data.progress || 0;
                            barEl.style.width = p + '%';
                            statusEl.textContent = data.data.message || '';
                            if (data.data.status === 'running') {
                                setTimeout(function() { poll(statusEl, barEl); }, 1000);
                            }
                        });
                }

                function bindRun(btn, dryRun) {
                    btn.addEventListener('click', function() {
                        var setId = btn.getAttribute('data-set-id');
                        if (!setId) {
                            alert('Sauvegarde d’abord le set pour obtenir un ID.');
                            return;
                        }
                        var statusEl = btn.closest('.gires-actions').querySelector('.gires-status');
                        var barEl = btn.closest('.gires-actions').querySelector('.gires-progress-bar');
                        statusEl.textContent = 'Démarrage...';
                        barEl.style.width = '0%';
                        var form = new FormData();
                        form.append('action', 'gires_cicd_run_job');
                        form.append('_ajax_nonce', nonce);
                        form.append('set_id', setId);
                        if (dryRun) {
                            form.append('dry_run', '1');
                        }
                        fetch(ajaxUrl, { method: 'POST', body: form })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    statusEl.textContent = data && data.data ? data.data.message : 'Erreur';
                                    return;
                                }
                                statusEl.textContent = 'En cours...';
                                poll(statusEl, barEl);
                            });
                    });
                }

                function bindClean(btn) {
                    btn.addEventListener('click', function() {
                        var setId = btn.getAttribute('data-set-id');
                        if (!setId) {
                            alert('Sauvegarde d’abord le set pour obtenir un ID.');
                            return;
                        }
                        var statusEl = btn.closest('.gires-actions').querySelector('.gires-status');
                        var form = new FormData();
                        form.append('action', 'gires_cicd_cleanup');
                        form.append('_ajax_nonce', nonce);
                        form.append('set_id', setId);
                        fetch(ajaxUrl, { method: 'POST', body: form })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    statusEl.textContent = data && data.data ? data.data.message : 'Erreur';
                                    return;
                                }
                                statusEl.textContent = data.data.message || 'Nettoyage OK';
                            });
                    });
                }

                document.querySelectorAll('.gires-run').forEach(function(btn) { bindRun(btn, false); });
                document.querySelectorAll('.gires-dry-run').forEach(function(btn) { bindRun(btn, true); });
                document.querySelectorAll('.gires-clean').forEach(bindClean);

                document.querySelectorAll('.gires-open-detail').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var index = btn.getAttribute('data-set-index');
                        var card = container.querySelector('.gires-set[data-index="' + index + '"]');
                        if (!card || !detailModal || !detailContent) return;
                        detailContent.innerHTML = '';
                        detailContent.appendChild(card.cloneNode(true));
                        detailModal.style.display = 'block';
                        detailContent.querySelectorAll('.gires-run').forEach(function(b) { bindRun(b, false); });
                        detailContent.querySelectorAll('.gires-dry-run').forEach(function(b) { bindRun(b, true); });
                        detailContent.querySelectorAll('.gires-clean').forEach(bindClean);
                        detailContent.querySelectorAll('.gires-table-info').forEach(bindTableInfo);
                    });
                });

                function bindTableInfo(btn) {
                    btn.addEventListener('click', function() {
                        var table = btn.getAttribute('data-table');
                        if (!table) return;
                        var form = new FormData();
                        form.append('action', 'gires_cicd_table_info');
                        form.append('_ajax_nonce', nonce);
                        form.append('table', table);
                        fetch(ajaxUrl, { method: 'POST', body: form })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    alert(data && data.data ? data.data.message : 'Erreur');
                                    return;
                                }
                                var schema = data.data.schema || [];
                                var example = data.data.example || {};
                                if (modalTitle) {
                                    modalTitle.textContent = 'Table: ' + table;
                                }
                                if (schemaBody) {
                                    schemaBody.innerHTML = '';
                                    schema.forEach(function(col) {
                                        var tr = document.createElement('tr');
                                        tr.innerHTML = '<td>' + (col.Field || '') + '</td>' +
                                            '<td>' + (col.Type || '') + '</td>' +
                                            '<td>' + (col.Null || '') + '</td>' +
                                            '<td>' + (col.Key || '') + '</td>' +
                                            '<td>' + (col.Default || '') + '</td>' +
                                            '<td>' + (col.Extra || '') + '</td>';
                                        schemaBody.appendChild(tr);
                                    });
                                }
                                if (exampleBody) {
                                    exampleBody.innerHTML = '';
                                    Object.keys(example).forEach(function(k) {
                                        var tr = document.createElement('tr');
                                        var val = example[k];
                                        tr.innerHTML = '<td>' + k + '</td><td>' + String(val) + '</td>';
                                        exampleBody.appendChild(tr);
                                    });
                                }
                                if (modal) {
                                    modal.style.display = 'block';
                                }
                            });
                    });
                }

                document.querySelectorAll('.gires-table-info').forEach(bindTableInfo);
            })();
        </script>

    <?php elseif ($tab === 'migrations') : ?>
        <h2>Migrations</h2>
        <p><strong>En attente:</strong> <?php echo count($pending); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="gires_cicd_run_migrations">
            <?php wp_nonce_field('gires_cicd_run_migrations'); ?>
            <button type="submit" class="button button-secondary">Exécuter les migrations</button>
        </form>

        <h3>Liste</h3>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Fichier</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file) : ?>
                    <?php $name = basename($file); ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo in_array($name, $applied, true) ? 'appliquée' : 'en attente'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($files)) : ?>
                    <tr><td colspan="2">Aucune migration trouvée.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php elseif ($tab === 'config') : ?>
        <h2>Configuration</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" id="gires-action" value="gires_cicd_save">
            <?php wp_nonce_field('gires_cicd_save'); ?>

            <h2>Agent REST</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Activer</th>
                    <td><label><input type="checkbox" name="rest_enabled" <?php checked($settings['rest_enabled']); ?>> Actif</label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="remote_url">URL du site distant</label></th>
                    <td>
                        <input type="text" id="remote_url" name="remote_url" value="<?php echo esc_attr($settings['remote_url']); ?>" class="regular-text" placeholder="https://exemple.com">
                        <button type="submit" class="button button-primary" data-action="gires_cicd_connect" id="gires-connect-btn" style="margin-left:8px;">Tester connexion</button>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rest_token">Token</label></th>
                    <td>
                        <input type="text" id="rest_token" name="rest_token" value="<?php echo esc_attr($settings['rest_token']); ?>" class="regular-text">
                        <p><button type="submit" name="gires_cicd_generate_token" value="1" class="button button-secondary">Générer</button></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rest_hmac_secret">HMAC secret</label></th>
                    <td>
                        <input type="text" id="rest_hmac_secret" name="rest_hmac_secret" value="<?php echo esc_attr($settings['rest_hmac_secret']); ?>" class="regular-text">
                        <p><button type="submit" name="gires_cicd_generate_hmac" value="1" class="button button-secondary">Générer</button></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rest_allowlist">IP autorisées</label></th>
                    <td>
                        <textarea id="rest_allowlist" name="rest_allowlist" rows="4" class="large-text"><?php echo esc_textarea($settings['rest_allowlist'] ?? ''); ?></textarea>
                        <p class="description">Une IP par ligne. Laisse vide pour autoriser toutes les IP.</p>
                    </td>
                </tr>
            </table>
            <p class="description" style="margin-top:6px;">
                Les clés API (Token + HMAC) doivent être identiques sur les deux serveurs. Ensuite, clique sur <strong>Tester connexion</strong>.
            </p>

            <h2>Avancé</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="migrations_path">Dossier migrations</label></th>
                    <td><input type="text" id="migrations_path" name="migrations_path" value="<?php echo esc_attr($settings['migrations_path']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="applied_option">Option WP (état)</label></th>
                    <td><input type="text" id="applied_option" name="applied_option" value="<?php echo esc_attr($settings['applied_option']); ?>" class="regular-text"></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" data-action="gires_cicd_save">Sauvegarder</button>
            </p>
        </form>
        <script>
            (function() {
                var actionInput = document.getElementById('gires-action');
                var card = document.getElementById('gires-conn-card');
                var icon = document.getElementById('gires-conn-icon');
                var statusText = document.getElementById('gires-conn-status');
                document.querySelectorAll('button[data-action]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        if (actionInput) {
                            actionInput.value = btn.getAttribute('data-action');
                        }
                        if (btn.getAttribute('data-action') === 'gires_cicd_connect') {
                            setCard('pending', 'Test en cours...');
                        }
                    });
                });

                function setCard(type, message) {
                    if (!card || !icon || !statusText) return;
                    var bg = 'linear-gradient(180deg,#9aa0a6,#7d8288)';
                    var symbol = '?';
                    var color = '#9aa0a6';
                    if (type === 'success') {
                        bg = 'linear-gradient(180deg,#39d353,#1f9d2f)';
                        symbol = '✓';
                        color = '#2e7d32';
                    }
                    if (type === 'error') {
                        bg = 'linear-gradient(180deg,#ff6b6b,#d64545)';
                        symbol = '✕';
                        color = '#c62828';
                    }
                    if (type === 'pending') {
                        bg = 'linear-gradient(180deg,#9aa0a6,#7d8288)';
                        symbol = '…';
                        color = '#6c7075';
                    }
                    icon.style.background = bg;
                    icon.textContent = symbol;
                    statusText.textContent = message;
                    statusText.style.color = color;
                }

                function autoTestConnection() {
                    var restEnabled = document.querySelector('input[name="rest_enabled"]');
                    var token = document.getElementById('rest_token');
                    var hmac = document.getElementById('rest_hmac_secret');
                    var url = document.getElementById('remote_url');
                    if (!restEnabled || !restEnabled.checked) return;
                    if (!token || !hmac || !url) return;
                    if (!token.value || !hmac.value || !url.value) return;
                    var params = new URLSearchParams(window.location.search);
                    if (params.get('connection') === '1') {
                        setCard('success', 'Connexion OK');
                        return;
                    }
                    if (params.get('connection') === '0') {
                        setCard('error', 'Connexion KO');
                        return;
                    }

                    setCard('pending', 'Test en cours...');
                    var form = new FormData();
                    form.append('action', 'gires_cicd_test_connection');
                    form.append('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('gires_cicd_job')); ?>');
                    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: form })
                        .then(r => r.json())
                        .then(function(data) {
                            if (!data || !data.success) {
                                setCard('error', 'Connexion KO');
                                return;
                            }
                            setCard('success', 'Connexion OK');
                        });
                }

                autoTestConnection();
            })();
        </script>
    <?php endif; ?>
</div>
