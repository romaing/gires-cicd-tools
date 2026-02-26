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
    <h1>CI/CD Tools <small style="font-size:12px; color:#6c7075;">v<?php echo esc_html(defined('GIRES_CICD_VERSION') ? GIRES_CICD_VERSION : ''); ?></small></h1>
    <?php if ($tab === 'config') : ?>
        <div id="gires-conn-card" style="display:inline-flex; align-items:center; gap:12px; padding:14px 18px; background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.08); margin:10px 0 6px;">
            <div id="gires-conn-icon" style="width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:26px; color:#fff; background:linear-gradient(180deg,#9aa0a6,#7d8288);">?</div>
            <div>
                <div id="gires-conn-title" style="font-weight:600; letter-spacing:1px; color:#5f6368;">REST</div>
                <div id="gires-conn-status" style="font-size:12px; color:#9aa0a6;">En attente</div>
            </div>
        </div>
        <div id="gires-ssh-card" style="display:inline-flex; align-items:center; gap:12px; padding:14px 18px; background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.08); margin:10px 0 6px;">
            <div id="gires-ssh-icon" style="width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:26px; color:#fff; background:linear-gradient(180deg,#9aa0a6,#7d8288);">?</div>
            <div>
                <div id="gires-ssh-title" style="font-weight:600; letter-spacing:1px; color:#5f6368;">SSH</div>
                <div id="gires-ssh-status" style="font-size:12px; color:#9aa0a6;">En attente</div>
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
        <?php if (isset($_GET['set_deleted']) && $_GET['set_deleted'] === '1') : ?>
            <?php if (!empty($_GET['deleted_count'])) : ?>
                <div class="notice notice-success"><p>Sets supprimés: <code><?php echo esc_html($_GET['deleted_count']); ?></code></p></div>
            <?php else : ?>
                <div class="notice notice-success"><p>Set supprimé: <code><?php echo esc_html($_GET['id'] ?? ''); ?></code></p></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (isset($_GET['set_deleted']) && $_GET['set_deleted'] === '0') : ?>
            <div class="notice notice-error"><p>Suppression du set impossible.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['set_duplicated']) && $_GET['set_duplicated'] === '1') : ?>
            <div class="notice notice-success"><p>Sets dupliqués: <code><?php echo esc_html($_GET['duplicated_count'] ?? '1'); ?></code></p></div>
        <?php endif; ?>
        <?php if (isset($_GET['set_duplicated']) && $_GET['set_duplicated'] === '0') : ?>
            <div class="notice notice-error"><p>Duplication des sets impossible.</p></div>
        <?php endif; ?>
        <?php if (empty($settings['remote_url'])) : ?>
            <div class="notice notice-warning"><p>URL distante manquante. Renseigne-la dans l’onglet Configuration.</p></div>
        <?php else : ?>
            <div class="notice notice-info"><p>URL distante: <code><?php echo esc_html($settings['remote_url']); ?></code></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="gires_cicd_save">
            <input type="hidden" name="gires_tab" value="sets">
            <?php wp_nonce_field('gires_cicd_save'); ?>

            <style>
                .gires-sets-table .check-column { width:24px; padding:8px 6px; text-align:center; }
                .gires-sets-table .check-column input { margin:0 auto; display:block; }
                .gires-sets-bulk label { margin:0; }
                .gires-sets-bulk { align-items:center; }
            </style>
            <table class="widefat striped gires-sets-table">
                <thead>
                    <tr>
                        <th class="check-column" style="width:24px;">
                            <input type="checkbox" id="gires-select-all-sets" aria-label="Tout sélectionner">
                        </th>
                        <th>Nom</th>
                        <th>Type</th>
                        <th>ID</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($sets ?? []) as $index => $set) : ?>
                        <tr data-set-index="<?php echo (int) $index; ?>">
                            <?php $type = $set['type'] ?? 'pull'; ?>
                            <td class="check-column">
                                <input type="checkbox" class="gires-set-select" value="<?php echo esc_attr($set['id'] ?? ''); ?>" aria-label="Sélectionner le set">
                            </td>
                            <td><?php echo esc_html($set['name'] ?? ''); ?></td>
                            <td><?php echo esc_html(strtoupper($type)); ?></td>
                            <td>
                                <code><?php echo esc_html($set['id'] ?? ''); ?></code>
                                <?php if ($type === 'pull') : ?>
                                    <span title="PULL" aria-hidden="true" style="margin-left:8px; font-size:20px; color:#2e7d32;">←</span>
                                <?php else : ?>
                                    <span title="PUSH" aria-hidden="true" style="margin-left:8px; font-size:20px; color:#c62828;">→</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-secondary gires-open-detail" data-set-index="<?php echo (int) $index; ?>">Détails</button>
                                <button type="button" class="button gires-unset" data-set-index="<?php echo (int) $index; ?>" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Unset</button>
                                <button type="button" class="button button-primary gires-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Lancer</button>
                                <button type="button" class="button button-secondary gires-dry-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Dry‑run</button>
                                <button type="button" class="button gires-stop" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Stop</button>
                                <button type="button" class="button gires-clean" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Nettoyer</button>
                                <button type="button" class="button gires-logs" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Logs</button>
                                <span class="gires-status" style="margin-left:10px;"></span>
                                <span class="gires-progress-text" style="margin-left:6px; color:#6c7075;"></span>
                                <div class="gires-progress" style="margin-top:6px; max-width:240px;">
                                    <div class="gires-progress-bar" style="height:6px; background:#2271b1; width:0%;"></div>
                                </div>
                                <div class="gires-steps"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="gires-sets-bulk" style="margin-top:12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="display:inline-flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="gires-delete-sets-confirm">
                    Confirmer suppression des sets sélectionnés
                </label>
                <button type="button" class="button button-secondary" id="gires-delete-sets-btn" disabled>Supprimer les sets sélectionnés</button>
                <span id="gires-delete-sets-count" style="color:#6c7075;"></span>
                <span style="margin-left:12px;"></span>
                <button type="button" class="button button-secondary" id="gires-duplicate-sets-btn" disabled>Dupliquer les sets sélectionnés</button>
                <span id="gires-duplicate-sets-count" style="color:#6c7075;"></span>
            </div>

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
                                <tr class="gires-id-row">
                                    <th scope="row">ID</th>
                                    <td>
                                        <input type="text" name="replication_sets[<?php echo $index; ?>][id]" value="<?php echo esc_attr($set['id'] ?? ''); ?>" class="regular-text" placeholder="slug_du_set">
                                        <p class="description">Slug éditable. Caractères autorisés: lettres, chiffres et underscore.</p>
                                    </td>
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
                                    <th scope="row">Exclure options</th>
                                    <td>
                                        <input type="text" name="replication_sets[<?php echo $index; ?>][exclude_option_prefix]" value="<?php echo esc_attr($set['exclude_option_prefix'] ?? ''); ?>" class="regular-text" placeholder="gires_">
                                        <p class="description">Préfixe des options à ignorer dans <code>wp_options</code> (ex: <code>gires_</code>).</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Code</th>
                                    <td>
                                        <input type="hidden" name="replication_sets[<?php echo $index; ?>][include_code]" value="0">
                                        <label><input type="checkbox" name="replication_sets[<?php echo $index; ?>][include_code]" value="1" <?php checked(!array_key_exists('include_code', $set) || !empty($set['include_code'])); ?>> Code</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Médias</th>
                                    <td>
                                        <input type="hidden" name="replication_sets[<?php echo $index; ?>][include_media]" value="0">
                                        <label><input type="checkbox" name="replication_sets[<?php echo $index; ?>][include_media]" value="1" <?php checked(!empty($set['include_media'])); ?>> Média</label>
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
                                        <div class="gires-tables-tools" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:center;">
                                            <input type="search" class="regular-text gires-table-search" placeholder="Rechercher une table...">
                                            <select class="gires-table-prefix-filter">
                                                <option value="">Tous les préfixes</option>
                                            </select>
                                            <label style="display:inline-flex; align-items:center; gap:6px;">
                                                <input type="checkbox" class="gires-table-active-only">
                                                Actives
                                            </label>
                                            <select class="gires-table-sort">
                                                <option value="az">Tri A → Z</option>
                                                <option value="za">Tri Z → A</option>
                                            </select>
                                            <span class="gires-table-visible-count" style="color:#6c7075;"></span>
                                        </div>
                                        <div class="gires-tables">
                                            <?php foreach ($all_tables as $table) : ?>
                                                <?php if ($is_gires_table($table)) : continue; endif; ?>
                                                <?php $checked = in_array($table, $selected_tables, true); ?>
                                                <label class="gires-table-item" data-table="<?php echo esc_attr($table); ?>" style="display:block;">
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
                                <button type="button" class="button gires-unset" data-set-index="<?php echo (int) $index; ?>" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Unset</button>
                                <button type="button" class="button button-primary gires-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Lancer</button>
                                <button type="button" class="button button-secondary gires-dry-run" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Dry‑run</button>
                                <button type="button" class="button gires-stop" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Stop</button>
                                <button type="button" class="button gires-clean" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Nettoyer</button>
                                <button type="button" class="button gires-logs" data-set-id="<?php echo esc_attr($set['id'] ?? ''); ?>">Logs</button>
                                <span class="gires-status" style="margin-left:10px;"></span>
                                <span class="gires-progress-text" style="margin-left:6px; color:#6c7075;"></span>
                                <div class="gires-progress" style="margin-top:8px; max-width:420px;">
                                    <div class="gires-progress-bar" style="height:8px; background:#2271b1; width:0%;"></div>
                                </div>
                                <div class="gires-steps"></div>
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
                        <div>
                            <button type="submit" class="button button-primary" data-action="gires_cicd_save">Sauvegarder</button>
                            <button type="button" class="button" id="gires-detail-close">Fermer</button>
                        </div>
                    </div>
                    <div id="gires-detail-content" style="margin-top:12px;"></div>
                </div>
            </div>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="gires-delete-sets-submit-form" style="display:none;">
            <input type="hidden" name="action" value="gires_cicd_delete_sets">
            <input type="hidden" name="set_ids" id="gires-delete-sets-ids" value="">
            <?php wp_nonce_field('gires_cicd_delete_sets'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="gires-duplicate-sets-submit-form" style="display:none;">
            <input type="hidden" name="action" value="gires_cicd_duplicate_sets">
            <input type="hidden" name="set_ids" id="gires-duplicate-sets-ids" value="">
            <?php wp_nonce_field('gires_cicd_duplicate_sets'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="gires-delete-set-form" style="display:none;">
            <input type="hidden" name="action" value="gires_cicd_delete_set">
            <input type="hidden" name="set_id" id="gires-delete-set-id" value="">
            <?php wp_nonce_field('gires_cicd_delete_set'); ?>
        </form>

        <script type="text/template" id="gires-set-template">
            <div class="postbox gires-set" data-index="__INDEX__" style="margin-bottom:16px;">
                <h3 class="hndle" style="padding:8px 12px;">Nouveau set</h3>
                <div class="inside">
                    <table class="form-table">
                        <tr class="gires-id-row"><th scope="row">ID</th><td><input type="text" name="replication_sets[__INDEX__][id]" value="" class="regular-text" placeholder="slug_du_set"><p class="description">Laisse vide pour génération automatique.</p></td></tr>
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
                        <tr><th scope="row">Exclure options</th><td><input type="text" name="replication_sets[__INDEX__][exclude_option_prefix]" value="gires_" class="regular-text" placeholder="gires_"><p class="description">Préfixe des options à ignorer dans <code>wp_options</code>.</p></td></tr>
                        <tr><th scope="row">Code</th><td><input type="hidden" name="replication_sets[__INDEX__][include_code]" value="0"><label><input type="checkbox" name="replication_sets[__INDEX__][include_code]" value="1" checked> Code</label></td></tr>
                        <tr><th scope="row">Médias</th><td><input type="hidden" name="replication_sets[__INDEX__][include_media]" value="0"><label><input type="checkbox" name="replication_sets[__INDEX__][include_media]" value="1" checked> Média</label></td></tr>
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
                            <div class="gires-tables-tools" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:center;">
                                <input type="search" class="regular-text gires-table-search" placeholder="Rechercher une table...">
                                <select class="gires-table-prefix-filter">
                                    <option value="">Tous les préfixes</option>
                                </select>
                                <label style="display:inline-flex; align-items:center; gap:6px;">
                                    <input type="checkbox" class="gires-table-active-only">
                                    Actives
                                </label>
                                <select class="gires-table-sort">
                                    <option value="az">Tri A → Z</option>
                                    <option value="za">Tri Z → A</option>
                                </select>
                                <span class="gires-table-visible-count" style="color:#6c7075;"></span>
                            </div>
                            <?php foreach ($all_tables as $table) : ?>
                                <?php if ($is_gires_table($table)) : continue; endif; ?>
                                <label class="gires-table-item" data-table="<?php echo esc_attr($table); ?>" style="display:block;">
                                    <input type="checkbox" name="replication_sets[__INDEX__][tables][]" value="<?php echo esc_attr($table); ?>" <?php echo $is_gires_table($table) ? '' : 'checked'; ?>>
                                    <?php echo esc_html($table); ?>
                                    <button type="button" class="button button-small gires-table-info" data-table="<?php echo esc_attr($table); ?>" style="margin-left:6px;">Schema</button>
                                </label>
                            <?php endforeach; ?>
                        </td></tr>
                    </table>
                            <div class="gires-actions">
                                <button type="button" class="button gires-unset" data-set-index="__INDEX__" data-set-id="">Unset</button>
                                <button type="button" class="button button-primary gires-run" data-set-id="">Lancer</button>
                                <button type="button" class="button button-secondary gires-dry-run" data-set-id="">Dry‑run</button>
                                <button type="button" class="button gires-stop" data-set-id="">Stop</button>
                                <button type="button" class="button gires-clean" data-set-id="">Nettoyer</button>
                                <button type="button" class="button gires-logs" data-set-id="">Logs</button>
                                <span class="gires-status" style="margin-left:10px;"></span>
                                <span class="gires-progress-text" style="margin-left:6px; color:#6c7075;"></span>
                                <div class="gires-progress" style="margin-top:8px; max-width:420px;">
                                    <div class="gires-progress-bar" style="height:8px; background:#2271b1; width:0%;"></div>
                                </div>
                                <div class="gires-steps"></div>
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

        <div id="gires-log-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999;">
            <div style="background:#fff; max-width:1000px; margin:6vh auto; padding:16px; border-radius:6px; max-height:80vh; overflow:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Logs</h3>
                    <button type="button" class="button" id="gires-log-close">Fermer</button>
                </div>
                <p class="description" id="gires-log-path" style="margin-top:6px;"></p>
                <pre id="gires-log-content" style="white-space:pre-wrap; background:#f6f7f7; padding:12px; border-radius:6px; max-height:60vh; overflow:auto;"></pre>
            </div>
        </div>

        <style>
            .gires-active-run { box-shadow: 0 0 0 2px #2271b1 inset; }
            .gires-active-dry { box-shadow: 0 0 0 2px #d9822b inset; }
            .gires-steps { margin-top:8px; display:flex; flex-wrap:wrap; gap:6px 10px; font-size:12px; color:#5f6368; }
            .gires-step { display:inline-flex; align-items:center; gap:6px; padding:2px 8px; border-radius:999px; background:#f1f3f4; }
            .gires-step--done { background:#e6f4ea; color:#1e8e3e; }
            .gires-step--current { background:#e8f0fe; color:#1a73e8; }
            .gires-step--error { background:#fce8e6; color:#d93025; }
            .gires-step__icon { font-weight:700; }
        </style>
        <script>
            (function() {
                var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var nonce = '<?php echo esc_js(wp_create_nonce('gires_cicd_job')); ?>';
                var i18n = {
                    saveSet: '<?php echo esc_js(__('Sauvegarde d’abord le set pour obtenir un ID.', 'gires-cicd-tools')); ?>',
                    errorUnknown: '<?php echo esc_js(__('Erreur inconnue', 'gires-cicd-tools')); ?>',
                    starting: '<?php echo esc_js(__('Démarrage...', 'gires-cicd-tools')); ?>',
                    running: '<?php echo esc_js(__('[REEL] Exécution en cours...', 'gires-cicd-tools')); ?>',
                    dryRunning: '<?php echo esc_js(__('[DRY-RUN] Exécution en cours...', 'gires-cicd-tools')); ?>',
                    stopped: '<?php echo esc_js(__('Arrêté', 'gires-cicd-tools')); ?>',
                    cleanupOk: '<?php echo esc_js(__('Nettoyage OK', 'gires-cicd-tools')); ?>',
                    tableLabel: '<?php echo esc_js(__('Table', 'gires-cicd-tools')); ?>',
                    logLoading: '<?php echo esc_js(__('Chargement des logs...', 'gires-cicd-tools')); ?>',
                    logEmpty: '<?php echo esc_js(__('Aucun log gires-cicd trouvé.', 'gires-cicd-tools')); ?>',
                    logError: '<?php echo esc_js(__('Impossible de lire le log.', 'gires-cicd-tools')); ?>',
                    sshGenOk: '<?php echo esc_js(__('Clé générée.', 'gires-cicd-tools')); ?>',
                    sshGenError: '<?php echo esc_js(__('Impossible de générer la clé.', 'gires-cicd-tools')); ?>',
                    sshOk: '<?php echo esc_js(__('Connexion SSH OK.', 'gires-cicd-tools')); ?>',
                    sshError: '<?php echo esc_js(__('Connexion SSH impossible.', 'gires-cicd-tools')); ?>',
                    previewTitle: '<?php echo esc_js(__('Prévisualisation avant exécution', 'gires-cicd-tools')); ?>',
                    previewSet: '<?php echo esc_js(__('Set', 'gires-cicd-tools')); ?>',
                    previewType: '<?php echo esc_js(__('Type', 'gires-cicd-tools')); ?>',
                    previewMode: '<?php echo esc_js(__('Mode', 'gires-cicd-tools')); ?>',
                    previewModeDry: '<?php echo esc_js(__('DRY-RUN', 'gires-cicd-tools')); ?>',
                    previewModeReal: '<?php echo esc_js(__('RÉEL', 'gires-cicd-tools')); ?>',
                    previewCode: '<?php echo esc_js(__('Code', 'gires-cicd-tools')); ?>',
                    previewMedia: '<?php echo esc_js(__('Médias', 'gires-cicd-tools')); ?>',
                    previewYes: '<?php echo esc_js(__('Oui', 'gires-cicd-tools')); ?>',
                    previewNo: '<?php echo esc_js(__('Non', 'gires-cicd-tools')); ?>',
                    previewSteps: '<?php echo esc_js(__('Étapes', 'gires-cicd-tools')); ?>',
                    previewTables: '<?php echo esc_js(__('Tables', 'gires-cicd-tools')); ?>',
                    previewSearchReplace: '<?php echo esc_js(__('Search/Replace', 'gires-cicd-tools')); ?>',
                    previewExcludePrefix: '<?php echo esc_js(__('Préfixe options exclu', 'gires-cicd-tools')); ?>',
                    previewConfirm: '<?php echo esc_js(__('Confirmer l’exécution ?', 'gires-cicd-tools')); ?>',
                    previewCanceled: '<?php echo esc_js(__('Exécution annulée', 'gires-cicd-tools')); ?>',
                    previewMoreTables: '<?php echo esc_js(__('... (liste tronquée)', 'gires-cicd-tools')); ?>',
                    stepLabels: <?php echo wp_json_encode([
                        'code_push' => __('Sync code vers prod', 'gires-cicd-tools'),
                        'code_pull' => __('Sync code vers local', 'gires-cicd-tools'),
                        'pre_pull_backup' => __('Sauvegarde distante', 'gires-cicd-tools'),
                        'maintenance_on_remote' => __('Maintenance distante activée', 'gires-cicd-tools'),
                        'db_export_local' => __('Export DB locale', 'gires-cicd-tools'),
                        'db_import_remote' => __('Import DB distant', 'gires-cicd-tools'),
                        'media_upload_remote' => __('Upload médias distant', 'gires-cicd-tools'),
                        'swap_remote' => __('Activation distante', 'gires-cicd-tools'),
                        'cleanup_remote' => __('Nettoyage distant', 'gires-cicd-tools'),
                        'maintenance_off_remote' => __('Maintenance distante désactivée', 'gires-cicd-tools'),
                        'maintenance_on_local' => __('Maintenance locale activée', 'gires-cicd-tools'),
                        'db_export_remote' => __('Export DB distant', 'gires-cicd-tools'),
                        'db_download_remote' => __('Téléchargement DB', 'gires-cicd-tools'),
                        'db_import_local' => __('Import DB local', 'gires-cicd-tools'),
                        'media_export_remote' => __('Export médias distant', 'gires-cicd-tools'),
                        'media_download_remote' => __('Téléchargement médias', 'gires-cicd-tools'),
                        'swap_local' => __('Activation locale', 'gires-cicd-tools'),
                        'cleanup_local' => __('Nettoyage local', 'gires-cicd-tools'),
                        'maintenance_off_local' => __('Maintenance locale désactivée', 'gires-cicd-tools'),
                        'smoke_local' => __('Smoke tests locaux', 'gires-cicd-tools'),
                    ]); ?>
                };
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
                var detailOriginal = null;
                var detailPlaceholder = null;
                var logModal = document.getElementById('gires-log-modal');
                var logClose = document.getElementById('gires-log-close');
                var logContent = document.getElementById('gires-log-content');
                var logPath = document.getElementById('gires-log-path');
                var genSshBtn = document.getElementById('gires-gen-ssh');
                var genSshStatus = document.getElementById('gires-gen-ssh-status');
                var sshPublic = document.getElementById('gires-ssh-public');
                var sshConfig = document.getElementById('gires-ssh-config');
                var sshDownload = document.getElementById('gires-ssh-download');
                var sshDownloadPub = document.getElementById('gires-ssh-download-pub');
                var testSshBtn = document.getElementById('gires-test-ssh');
                var testSshStatus = document.getElementById('gires-test-ssh-status');
                var pendingStatusTimers = new WeakMap();

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
                        if (detailOriginal && detailPlaceholder && detailPlaceholder.parentNode) {
                            detailPlaceholder.parentNode.insertBefore(detailOriginal, detailPlaceholder);
                            detailPlaceholder.remove();
                        }
                        detailOriginal = null;
                        detailPlaceholder = null;
                        detailModal.style.display = 'none';
                    });
                    detailModal.addEventListener('click', function(e) {
                        if (e.target === detailModal) {
                            if (detailOriginal && detailPlaceholder && detailPlaceholder.parentNode) {
                                detailPlaceholder.parentNode.insertBefore(detailOriginal, detailPlaceholder);
                                detailPlaceholder.remove();
                            }
                            detailOriginal = null;
                            detailPlaceholder = null;
                            detailModal.style.display = 'none';
                        }
                    });
                }

                if (logClose && logModal) {
                    logClose.addEventListener('click', function() {
                        logModal.style.display = 'none';
                    });
                    logModal.addEventListener('click', function(e) {
                        if (e.target === logModal) {
                            logModal.style.display = 'none';
                        }
                    });
                }

                if (genSshBtn) {
                    genSshBtn.addEventListener('click', function() {
                        if (genSshStatus) genSshStatus.textContent = i18n.starting;
                        if (sshPublic) sshPublic.textContent = '';
                        if (sshConfig) sshConfig.textContent = '';
                        if (sshDownload) sshDownload.style.display = 'none';
                        var form = new FormData();
                        form.append('action', 'gires_cicd_generate_ssh_key');
                        form.append('_ajax_nonce', nonce);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(function(r) {
                                return r.json().catch(function() {
                                    throw new Error('json');
                                });
                            })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    if (genSshStatus) genSshStatus.textContent = data && data.data ? data.data.message : i18n.sshGenError;
                                    return;
                                }
                                if (sshPublic) sshPublic.textContent = data.data.public_key || '';
                                if (sshConfig) sshConfig.textContent = data.data.config_snippet || '';
                                if (sshDownload && data.data.download_url) {
                                    sshDownload.href = data.data.download_url;
                                    sshDownload.style.display = 'inline-block';
                                }
                                if (genSshStatus) genSshStatus.textContent = i18n.sshGenOk;
                            })
                            .catch(function() {
                                if (genSshStatus) genSshStatus.textContent = i18n.sshGenError;
                            });
                    });
                }

                if (testSshBtn) {
                    testSshBtn.addEventListener('click', function() {
                        if (testSshStatus) testSshStatus.textContent = i18n.starting;
                        var form = new FormData();
                        form.append('action', 'gires_cicd_test_ssh');
                        form.append('_ajax_nonce', nonce);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(function(r) {
                                return r.json().catch(function() {
                                    throw new Error('json');
                                });
                            })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    if (testSshStatus) testSshStatus.textContent = data && data.data ? data.data.message : i18n.sshError;
                                    return;
                                }
                                if (testSshStatus) testSshStatus.textContent = data.data.message || i18n.sshOk;
                            })
                            .catch(function() {
                                if (testSshStatus) testSshStatus.textContent = i18n.sshError;
                            });
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

                function getTablePrefix(tableName) {
                    var idx = tableName.indexOf('_');
                    if (idx <= 0) return '(sans prefixe)';
                    return tableName.slice(0, idx + 1);
                }

                function bindTableFilters(scope) {
                    if (!scope) return;
                    var tools = scope.querySelector('.gires-tables-tools');
                    var list = scope.querySelector('.gires-tables');
                    if (!tools || !list) return;

                    var searchInput = tools.querySelector('.gires-table-search');
                    var prefixSelect = tools.querySelector('.gires-table-prefix-filter');
                    var activeOnlyCheckbox = tools.querySelector('.gires-table-active-only');
                    var sortSelect = tools.querySelector('.gires-table-sort');
                    var countEl = tools.querySelector('.gires-table-visible-count');
                    var labels = Array.prototype.slice.call(list.querySelectorAll('.gires-table-item'));
                    if (!labels.length) return;

                    var prefixes = {};
                    labels.forEach(function(label) {
                        var table = label.getAttribute('data-table') || '';
                        prefixes[getTablePrefix(table)] = true;
                    });
                    if (prefixSelect) {
                        Object.keys(prefixes).sort().forEach(function(prefix) {
                            var option = document.createElement('option');
                            option.value = prefix;
                            option.textContent = prefix;
                            prefixSelect.appendChild(option);
                        });
                    }

                    function applyTableFilters() {
                        var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
                        var selectedPrefix = prefixSelect ? prefixSelect.value : '';
                        var activeOnly = activeOnlyCheckbox ? !!activeOnlyCheckbox.checked : false;
                        var sortDir = sortSelect ? sortSelect.value : 'az';

                        labels.sort(function(a, b) {
                            var an = (a.getAttribute('data-table') || '').toLowerCase();
                            var bn = (b.getAttribute('data-table') || '').toLowerCase();
                            if (an === bn) return 0;
                            if (sortDir === 'za') return an < bn ? 1 : -1;
                            return an > bn ? 1 : -1;
                        }).forEach(function(label) {
                            list.appendChild(label);
                        });

                        var visible = 0;
                        labels.forEach(function(label) {
                            var table = label.getAttribute('data-table') || '';
                            var tableLc = table.toLowerCase();
                            var prefix = getTablePrefix(table);
                            var checkbox = label.querySelector('input[type="checkbox"]');
                            var isActive = checkbox ? checkbox.checked : false;
                            var matchesQuery = query === '' || tableLc.indexOf(query) !== -1;
                            var matchesPrefix = selectedPrefix === '' || prefix === selectedPrefix;
                            var matchesActive = !activeOnly || isActive;
                            var show = matchesQuery && matchesPrefix && matchesActive;
                            label.style.display = show ? 'block' : 'none';
                            if (show) visible++;
                        });

                        if (countEl) {
                            countEl.textContent = visible + ' / ' + labels.length + ' table(s)';
                        }
                    }

                    if (searchInput) searchInput.addEventListener('input', applyTableFilters);
                    if (prefixSelect) prefixSelect.addEventListener('change', applyTableFilters);
                    if (activeOnlyCheckbox) activeOnlyCheckbox.addEventListener('change', applyTableFilters);
                    if (sortSelect) sortSelect.addEventListener('change', applyTableFilters);
                    labels.forEach(function(label) {
                        var checkbox = label.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.addEventListener('change', applyTableFilters);
                    });
                    applyTableFilters();
                }

                document.querySelectorAll('.gires-search-replace').forEach(bindSearchReplace);
                document.querySelectorAll('.gires-set').forEach(bindTableFilters);

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
                        bindStop(div.querySelector('.gires-stop'));
                        bindClean(div.querySelector('.gires-clean'));
                        bindLogs(div.querySelector('.gires-logs'));
                        bindUnset(div.querySelector('.gires-unset'));
                        div.querySelectorAll('.gires-table-info').forEach(bindTableInfo);
                        bindTableFilters(div);
                        if (detailModal && detailContent) {
                            detailContent.innerHTML = '';
                            detailOriginal = div;
                            detailPlaceholder = document.createComment('gires-set-placeholder');
                            div.parentNode.insertBefore(detailPlaceholder, div);
                            detailContent.appendChild(div);
                            detailModal.style.display = 'block';
                        }
                    });
                }

                function findStatusElements(btn) {
                    var container = btn.closest('.gires-actions') || btn.closest('td');
                    if (!container) return { statusEl: null, barEl: null };
                    return {
                        statusEl: container.querySelector('.gires-status'),
                        barEl: container.querySelector('.gires-progress-bar'),
                        textEl: container.querySelector('.gires-progress-text'),
                        stepsEl: container.querySelector('.gires-steps')
                    };
                }

                function setButtonsState(container, running, mode) {
                    if (!container) return;
                    container.querySelectorAll('.gires-run, .gires-dry-run, .gires-clean').forEach(function(b) {
                        b.disabled = running;
                    });
                    container.querySelectorAll('.gires-stop').forEach(function(b) {
                        b.disabled = !running;
                    });
                    container.querySelectorAll('.gires-run, .gires-dry-run').forEach(function(b) {
                        b.classList.remove('gires-active-run', 'gires-active-dry');
                    });
                    if (running) {
                        var target = container.querySelector(mode === 'dry' ? '.gires-dry-run' : '.gires-run');
                        if (target) {
                            target.classList.add(mode === 'dry' ? 'gires-active-dry' : 'gires-active-run');
                        }
                    }
                    var statusEl = container.querySelector('.gires-status');
                    if (statusEl && mode) {
                        statusEl.textContent = (mode === 'dry' ? i18n.dryRunning : i18n.running);
                    }
                }

                function getCurrentStepLabel(container) {
                    if (!container) return '';
                    var current = container.querySelector('.gires-step--current .gires-step__label');
                    if (current && current.textContent) {
                        return current.textContent.trim();
                    }
                    return '';
                }

                function clearPendingStatus(container) {
                    if (!container) return;
                    var current = pendingStatusTimers.get(container);
                    if (current && current.timer) {
                        clearInterval(current.timer);
                    }
                    pendingStatusTimers.delete(container);
                }

                function isRunStillActive(container) {
                    if (!container) return false;
                    return !!container.querySelector('.gires-active-run, .gires-active-dry');
                }

                function startPendingStatus(container, statusEl, mode) {
                    if (!container || !statusEl) return;
                    clearPendingStatus(container);
                    var startedAt = Date.now();
                    var tick = function() {
                        var elapsed = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
                        var label = getCurrentStepLabel(container);
                        var progressEl = container.querySelector('.gires-progress-text');
                        var percent = progressEl && progressEl.textContent ? progressEl.textContent.trim() : '0%';
                        var base = mode === 'dry' ? '[DRY-RUN] Exécution en cours...' : '[REEL] Exécution en cours...';
                        if (label) {
                            statusEl.textContent = base + ' ' + label + ' - ' + percent + ' - ' + elapsed + 's';
                        } else {
                            statusEl.textContent = base + ' ' + percent + ' - ' + elapsed + 's';
                        }
                    };
                    tick();
                    var timer = setInterval(tick, 1000);
                    pendingStatusTimers.set(container, { timer: timer });
                }

                function parseAjaxJson(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        var start = text ? text.lastIndexOf('{"success"') : -1;
                        if (start >= 0) {
                            try {
                                return JSON.parse(text.slice(start));
                            } catch (e2) {}
                        }
                    }
                    return null;
                }

                function postAjax(formData) {
                    return fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                        .then(function(r) { return r.text(); })
                        .then(function(text) {
                            var data = parseAjaxJson(text);
                            if (!data) {
                                var snippet = (text || '').replace(/\s+/g, ' ').trim().slice(0, 220);
                                throw new Error(snippet ? ('Réponse AJAX invalide: ' + snippet) : 'Réponse AJAX invalide');
                            }
                            return data;
                        });
                }

                function poll(statusEl, barEl, textEl) {
                    var form = new FormData();
                    form.append('action', 'gires_cicd_job_step');
                    form.append('_ajax_nonce', nonce);
                    var container = statusEl ? (statusEl.closest('.gires-actions') || statusEl.closest('td')) : null;
                    var mode = container && container.querySelector('.gires-active-dry') ? 'dry' : 'run';
                    startPendingStatus(container, statusEl, mode);
                    postAjax(form)
                        .then(function(data) {
                            clearPendingStatus(container);
                            var keepPolling = false;
                            try {
                                if (!data || !data.success) {
                                    if (statusEl) statusEl.textContent = data && data.data ? data.data.message : i18n.errorUnknown;
                                    if (isRunStillActive(container)) {
                                        setTimeout(function() { poll(statusEl, barEl, textEl); }, 1500);
                                    } else {
                                        setButtonsState(container, false);
                                    }
                                    return;
                                }
                                var p = data.data.progress || 0;
                                if (barEl) barEl.style.width = p + '%';
                                if (textEl) textEl.textContent = p + '%';
                                if (statusEl) statusEl.textContent = data.data.message || '';
                                var stepsEl = container ? container.querySelector('.gires-steps') : null;
                                renderSteps(stepsEl, data.data.steps || [], data.data.step_index || 0, data.data.status || 'running');
                                keepPolling = data.data.status === 'running';
                                if (!keepPolling) {
                                    setButtonsState(container, false);
                                }
                            } catch (e) {
                                if (statusEl) statusEl.textContent = i18n.errorUnknown;
                                if (isRunStillActive(container)) {
                                    setTimeout(function() { poll(statusEl, barEl, textEl); }, 1500);
                                } else {
                                    setButtonsState(container, false);
                                }
                                return;
                            }
                            if (keepPolling) {
                                setTimeout(function() { poll(statusEl, barEl, textEl); }, 1000);
                            }
                        })
                        .catch(function(err) {
                            clearPendingStatus(container);
                            if (statusEl) statusEl.textContent = (err && err.message) ? err.message : i18n.errorUnknown;
                            if (isRunStillActive(container)) {
                                setTimeout(function() { poll(statusEl, barEl, textEl); }, 1500);
                            } else {
                                setButtonsState(container, false);
                            }
                        });
                }

                function renderSteps(stepsEl, steps, stepIndex, status) {
                    if (!stepsEl || !steps || !steps.length) return;
                    var idx = parseInt(stepIndex || 0, 10);
                    var isDone = status === 'done';
                    stepsEl.innerHTML = '';
                    steps.forEach(function(step, i) {
                        var state = 'pending';
                        if (isDone || i < idx) {
                            state = 'done';
                        } else if (i === idx) {
                            state = status === 'error' ? 'error' : 'current';
                        }
                        var icon = state === 'done' ? '✓' : (state === 'current' ? '⏳' : (state === 'error' ? '✕' : '•'));
                        var label = (i18n.stepLabels && i18n.stepLabels[step]) ? i18n.stepLabels[step] : step;
                        var el = document.createElement('div');
                        el.className = 'gires-step gires-step--' + state;
                        el.innerHTML = '<span class="gires-step__icon">' + icon + '</span><span class="gires-step__label">' + label + '</span>';
                        stepsEl.appendChild(el);
                    });
                }

                function buildPreviewText(preview) {
                    var lines = [];
                    lines.push(i18n.previewTitle);
                    lines.push('--------------------------------');
                    lines.push(i18n.previewSet + ': ' + (preview.set_name || preview.set_id || ''));
                    lines.push(i18n.previewType + ': ' + ((preview.type || '').toUpperCase()));
                    lines.push(i18n.previewMode + ': ' + (preview.dry_run ? i18n.previewModeDry : i18n.previewModeReal));
                    lines.push(i18n.previewCode + ': ' + (preview.include_code ? i18n.previewYes : i18n.previewNo));
                    lines.push(i18n.previewMedia + ': ' + (preview.include_media ? i18n.previewYes : i18n.previewNo));
                    lines.push(i18n.previewExcludePrefix + ': ' + (preview.exclude_option_prefix || '-'));
                    lines.push('');

                    var steps = Array.isArray(preview.steps) ? preview.steps : [];
                    lines.push(i18n.previewSteps + ' (' + steps.length + '):');
                    steps.forEach(function(step) {
                        var label = (i18n.stepLabels && i18n.stepLabels[step]) ? i18n.stepLabels[step] : step;
                        lines.push('• ' + label);
                    });
                    lines.push('');

                    var tables = Array.isArray(preview.tables_preview) ? preview.tables_preview : [];
                    var tablesCount = parseInt(preview.tables_count || 0, 10);
                    lines.push(i18n.previewTables + ' (' + tablesCount + '):');
                    tables.forEach(function(table) {
                        lines.push('• ' + table);
                    });
                    if (preview.tables_preview_truncated) {
                        lines.push(i18n.previewMoreTables);
                    }
                    lines.push('');

                    var sr = Array.isArray(preview.search_replace) ? preview.search_replace : [];
                    lines.push(i18n.previewSearchReplace + ' (' + sr.length + '):');
                    sr.forEach(function(pair) {
                        lines.push('• ' + (pair.search || '') + '  =>  ' + (pair.replace || ''));
                    });
                    if (!sr.length) {
                        lines.push('• -');
                    }
                    lines.push('');
                    lines.push(i18n.previewConfirm);
                    return lines.join('\n');
                }

                function bindRun(btn, dryRun) {
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        var setId = btn.getAttribute('data-set-id');
                        if (!setId) {
                            alert(i18n.saveSet);
                            return;
                        }
                        var els = findStatusElements(btn);
                        var statusEl = els.statusEl;
                        var barEl = els.barEl;
                        var textEl = els.textEl;
                        var stepsEl = els.stepsEl;
                        var container = btn.closest('.gires-actions') || btn.closest('td');
                        if (statusEl) statusEl.textContent = i18n.starting;

                        var previewForm = new FormData();
                        previewForm.append('action', 'gires_cicd_preview_job');
                        previewForm.append('_ajax_nonce', nonce);
                        previewForm.append('set_id', setId);
                        if (dryRun) {
                            previewForm.append('dry_run', '1');
                        }

                        postAjax(previewForm)
                            .then(function(previewResp) {
                                if (!previewResp || !previewResp.success) {
                                    throw new Error(previewResp && previewResp.data ? previewResp.data.message : i18n.errorUnknown);
                                }
                                var ok = window.confirm(buildPreviewText(previewResp.data || {}));
                                if (!ok) {
                                    if (statusEl) statusEl.textContent = i18n.previewCanceled;
                                    setButtonsState(container, false);
                                    return;
                                }

                                if (barEl) barEl.style.width = '0%';
                                if (textEl) textEl.textContent = '0%';
                                if (stepsEl) stepsEl.innerHTML = '';
                                setButtonsState(container, true, dryRun ? 'dry' : 'run');

                                var runForm = new FormData();
                                runForm.append('action', 'gires_cicd_run_job');
                                runForm.append('_ajax_nonce', nonce);
                                runForm.append('set_id', setId);
                                if (dryRun) {
                                    runForm.append('dry_run', '1');
                                }
                                return postAjax(runForm)
                                    .then(function(data) {
                                        if (!data || !data.success) {
                                            if (statusEl) statusEl.textContent = data && data.data ? data.data.message : i18n.errorUnknown;
                                            setButtonsState(container, false);
                                            return;
                                        }
                                        if (statusEl) statusEl.textContent = dryRun ? i18n.dryRunning : i18n.running;
                                        renderSteps(stepsEl, data.data.steps || [], data.data.step_index || 0, data.data.status || 'running');
                                        poll(statusEl, barEl, textEl);
                                    });
                            })
                            .catch(function(err) {
                                if (statusEl) statusEl.textContent = (err && err.message) ? err.message : i18n.errorUnknown;
                                setButtonsState(container, false);
                            });
                    });
                }

                function bindStop(btn) {
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        var els = findStatusElements(btn);
                        var statusEl = els.statusEl;
                        var barEl = els.barEl;
                        var textEl = els.textEl;
                        var container = btn.closest('.gires-actions') || btn.closest('td');
                        var form = new FormData();
                        form.append('action', 'gires_cicd_stop_job');
                        form.append('_ajax_nonce', nonce);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(function(r) { return r.text(); })
                            .then(function(text) {
                                var data = parseAjaxJson(text);
                                if (!data) {
                                    var snippet = (text || '').replace(/\s+/g, ' ').trim().slice(0, 220);
                                    throw new Error(snippet ? ('Réponse AJAX invalide: ' + snippet) : 'Réponse AJAX invalide');
                                }
                                return data;
                            })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    if (statusEl) statusEl.textContent = data && data.data ? data.data.message : i18n.errorUnknown;
                                    return;
                                }
                                var p = data.data.progress || 0;
                                if (barEl) barEl.style.width = p + '%';
                                if (textEl) textEl.textContent = p + '%';
                                if (statusEl) statusEl.textContent = data.data.message || i18n.stopped;
                                if (data.data && data.data.status === 'stopping') {
                                    var mode = container && container.querySelector('.gires-active-dry') ? 'dry' : 'run';
                                    setButtonsState(container, true, mode);
                                    setTimeout(function() { poll(statusEl, barEl, textEl); }, 500);
                                } else {
                                    setButtonsState(container, false);
                                }
                            })
                            .catch(function(err) {
                                if (statusEl) statusEl.textContent = (err && err.message) ? err.message : i18n.errorUnknown;
                            });
                    });
                }

                function bindClean(btn) {
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        var setId = btn.getAttribute('data-set-id');
                        if (!setId) {
                            alert(i18n.saveSet);
                            return;
                        }
                        var els = findStatusElements(btn);
                        var statusEl = els.statusEl;
                        var form = new FormData();
                        form.append('action', 'gires_cicd_cleanup');
                        form.append('_ajax_nonce', nonce);
                        form.append('set_id', setId);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    if (statusEl) statusEl.textContent = data && data.data ? data.data.message : i18n.errorUnknown;
                                    return;
                                }
                                if (statusEl) statusEl.textContent = data.data.message || i18n.cleanupOk;
                            });
                    });
                }

                function bindLogs(btn) {
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        if (!logModal || !logContent) return;
                        logContent.textContent = i18n.logLoading;
                        if (logPath) logPath.textContent = '';
                        var form = new FormData();
                        form.append('action', 'gires_cicd_tail_log');
                        form.append('_ajax_nonce', nonce);
                        form.append('lines', '200');
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    logContent.textContent = data && data.data ? data.data.message : i18n.logError;
                                    return;
                                }
                                if (logPath && data.data.path) {
                                    logPath.textContent = data.data.path;
                                }
                                logContent.textContent = data.data.lines ? data.data.lines : i18n.logEmpty;
                            });
                        logModal.style.display = 'block';
                    });
                }

                document.querySelectorAll('.gires-run').forEach(function(btn) { bindRun(btn, false); });
                document.querySelectorAll('.gires-dry-run').forEach(function(btn) { bindRun(btn, true); });
                document.querySelectorAll('.gires-stop').forEach(bindStop);
                document.querySelectorAll('.gires-clean').forEach(bindClean);
                document.querySelectorAll('.gires-logs').forEach(bindLogs);
                document.querySelectorAll('.gires-unset').forEach(bindUnset);

                document.querySelectorAll('.gires-open-detail').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var index = btn.getAttribute('data-set-index');
                        var card = container.querySelector('.gires-set[data-index="' + index + '"]');
                        if (!card || !detailModal || !detailContent) return;
                        detailContent.innerHTML = '';
                        detailOriginal = card;
                        detailPlaceholder = document.createComment('gires-set-placeholder');
                        card.parentNode.insertBefore(detailPlaceholder, card);
                        detailContent.appendChild(card);
                        detailModal.style.display = 'block';
                        card.querySelectorAll('.gires-run').forEach(function(b) { bindRun(b, false); });
                        card.querySelectorAll('.gires-dry-run').forEach(function(b) { bindRun(b, true); });
                        card.querySelectorAll('.gires-stop').forEach(bindStop);
                        card.querySelectorAll('.gires-clean').forEach(bindClean);
                        card.querySelectorAll('.gires-logs').forEach(bindLogs);
                        card.querySelectorAll('.gires-unset').forEach(bindUnset);
                        card.querySelectorAll('.gires-table-info').forEach(bindTableInfo);
                    });
                });

                function bindUnset(btn) {
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        var setId = btn.getAttribute('data-set-id');
                        if (!setId) return;
                        if (!window.confirm('Confirmer la suppression de ce set ?')) {
                            return;
                        }
                        if (!deleteSetForm || !deleteSetIdInput) return;
                        deleteSetIdInput.value = setId;
                        deleteSetForm.submit();
                    });
                }

                function bindTableInfo(btn) {
                    if (!btn) return;
                    btn.addEventListener('click', function() {
                        var table = btn.getAttribute('data-table');
                        if (!table) return;
                        var form = new FormData();
                        form.append('action', 'gires_cicd_table_info');
                        form.append('_ajax_nonce', nonce);
                        form.append('table', table);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    alert(data && data.data ? data.data.message : i18n.errorUnknown);
                                    return;
                                }
                                var schema = data.data.schema || [];
                                var example = data.data.example || {};
                                if (modalTitle) {
                                    modalTitle.textContent = i18n.tableLabel + ': ' + table;
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

                var deleteSetsSubmitForm = document.getElementById('gires-delete-sets-submit-form');
                var deleteSetsIdsInput = document.getElementById('gires-delete-sets-ids');
                var deleteSetForm = document.getElementById('gires-delete-set-form');
                var deleteSetIdInput = document.getElementById('gires-delete-set-id');
                var deleteSetsBtn = document.getElementById('gires-delete-sets-btn');
                var deleteSetsConfirm = document.getElementById('gires-delete-sets-confirm');
                var deleteSetsCount = document.getElementById('gires-delete-sets-count');
                var duplicateSetsSubmitForm = document.getElementById('gires-duplicate-sets-submit-form');
                var duplicateSetsIdsInput = document.getElementById('gires-duplicate-sets-ids');
                var duplicateSetsBtn = document.getElementById('gires-duplicate-sets-btn');
                var duplicateSetsCount = document.getElementById('gires-duplicate-sets-count');
                var selectAll = document.getElementById('gires-select-all-sets');
                var setCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.gires-set-select'));

                function updateDeleteSetsState() {
                    var selected = setCheckboxes.filter(function(cb) { return cb.checked; });
                    var count = selected.length;
                    if (deleteSetsCount) {
                        deleteSetsCount.textContent = count > 0 ? (count + ' sélectionné(s)') : '';
                    }
                    if (duplicateSetsCount) {
                        duplicateSetsCount.textContent = count > 0 ? (count + ' sélectionné(s)') : '';
                    }
                    var canDelete = count > 0 && deleteSetsConfirm && deleteSetsConfirm.checked;
                    if (deleteSetsBtn) {
                        deleteSetsBtn.disabled = !canDelete;
                    }
                    if (duplicateSetsBtn) {
                        duplicateSetsBtn.disabled = count === 0;
                    }
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        var checked = selectAll.checked;
                        setCheckboxes.forEach(function(cb) { cb.checked = checked; });
                        updateDeleteSetsState();
                    });
                }

                setCheckboxes.forEach(function(cb) {
                    cb.addEventListener('change', updateDeleteSetsState);
                });

                if (deleteSetsConfirm) {
                    deleteSetsConfirm.addEventListener('change', updateDeleteSetsState);
                }

                if (deleteSetsBtn) {
                    deleteSetsBtn.addEventListener('click', function(e) {
                        var selected = setCheckboxes.filter(function(cb) { return cb.checked; });
                        if (!deleteSetsConfirm || !deleteSetsConfirm.checked || selected.length === 0) {
                            e.preventDefault();
                            updateDeleteSetsState();
                            return;
                        }
                        if (!window.confirm('Confirmer la suppression des sets sélectionnés ?')) {
                            e.preventDefault();
                            return;
                        }
                        if (!deleteSetsSubmitForm || !deleteSetsIdsInput) return;
                        deleteSetsIdsInput.value = selected.map(function(cb) { return cb.value; }).join(',');
                        deleteSetsSubmitForm.submit();
                    });
                }

                if (duplicateSetsBtn) {
                    duplicateSetsBtn.addEventListener('click', function(e) {
                        var selected = setCheckboxes.filter(function(cb) { return cb.checked; });
                        if (selected.length === 0) {
                            e.preventDefault();
                            updateDeleteSetsState();
                            return;
                        }
                        if (!duplicateSetsSubmitForm || !duplicateSetsIdsInput) return;
                        duplicateSetsIdsInput.value = selected.map(function(cb) { return cb.value; }).join(',');
                        duplicateSetsSubmitForm.submit();
                    });
                }

                updateDeleteSetsState();
            })();
        </script>

    <?php elseif ($tab === 'migrations') : ?>
        <h2>Migrations</h2>
        <p><strong>En attente:</strong> <?php echo count($pending); ?></p>
        <?php if (!empty($_GET['deleted'])) : ?>
            <div class="notice notice-success"><p>Migration supprimée: <code><?php echo esc_html($_GET['file'] ?? ''); ?></code></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['del_error'])) : ?>
            <div class="notice notice-error"><p>Suppression impossible: <?php echo esc_html($_GET['message'] ?? ''); ?></p></div>
        <?php endif; ?>

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
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file) : ?>
                    <?php $name = basename($file); ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo in_array($name, $applied, true) ? 'appliquée' : 'en attente'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gires-delete-migration-form" style="display:flex; align-items:center; gap:10px;">
                                <input type="hidden" name="action" value="gires_cicd_delete_migration">
                                <input type="hidden" name="migration_file" value="<?php echo esc_attr($name); ?>">
                                <?php wp_nonce_field('gires_cicd_delete_migration'); ?>
                                <label style="display:inline-flex; align-items:center; gap:4px;">
                                    <input type="checkbox" class="gires-delete-confirm">
                                    Confirmer
                                </label>
                                <button type="submit" class="button button-secondary gires-delete-btn" disabled>Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($files)) : ?>
                    <tr><td colspan="3">Aucune migration trouvée.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <script>
            (function() {
                document.querySelectorAll('.gires-delete-migration-form').forEach(function(form) {
                    var checkbox = form.querySelector('.gires-delete-confirm');
                    var button = form.querySelector('.gires-delete-btn');
                    if (!checkbox || !button) return;

                    checkbox.addEventListener('change', function() {
                        button.disabled = !checkbox.checked;
                    });

                    form.addEventListener('submit', function(e) {
                        if (!checkbox.checked) {
                            e.preventDefault();
                            return;
                        }
                        if (!window.confirm('Confirmer la suppression de cette migration ?')) {
                            e.preventDefault();
                        }
                    });
                });
            })();
        </script>
    <?php elseif ($tab === 'config') : ?>
        <h2>Configuration</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" id="gires-action" value="gires_cicd_save">
            <input type="hidden" name="gires_tab" value="config">
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
                        <p class="description">À renseigner uniquement sur le site local (URL de la prod). Sur la prod, ce champ peut rester vide.</p>
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
            <p class="description">Ces champs concernent uniquement la sync code via SSH et la base distante (prod).</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="ssh_host">SSH host (prod)</label></th>
                    <td><input type="text" id="ssh_host" name="ssh_host" value="<?php echo esc_attr($settings['ssh_host']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ssh_user">SSH user (prod)</label></th>
                    <td><input type="text" id="ssh_user" name="ssh_user" value="<?php echo esc_attr($settings['ssh_user']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ssh_path">SSH path (prod)</label></th>
                    <td>
                        <input type="text" id="ssh_path" name="ssh_path" value="<?php echo esc_attr($settings['ssh_path']); ?>" class="regular-text" placeholder="/path/to/wp">
                        <p><button type="button" class="button button-secondary" id="gires-test-ssh">Tester connexion SSH</button> <span id="gires-test-ssh-status" style="margin-left:8px; color:#6c7075;"></span></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rsync_excludes">Exclusions rsync</label></th>
                    <td>
                        <textarea id="rsync_excludes" name="rsync_excludes" rows="5" class="large-text"><?php echo esc_textarea($settings['rsync_excludes'] ?? ''); ?></textarea>
                        <p class="description">Un chemin par ligne. Exemples: <code>wp-config.php</code>, <code>.htaccess</code>, <code>wp-content/uploads/</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Smoke tests</th>
                    <td>
                        <label><input type="checkbox" name="smoke_after_sync" <?php checked(!empty($settings['smoke_after_sync'])); ?>> Activer en fin de run (inclut Dry-run)</label>
                        <p class="description">Exécute <code>scripts/smoke_wp.sh</code> côté local après le workflow.</p>
                        <label><input type="checkbox" name="smoke_strict" <?php checked(!empty($settings['smoke_strict'])); ?>> Mode strict (fail si smoke KO)</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Clé SSH</th>
                    <td>
                        <p><button type="button" class="button button-secondary" id="gires-gen-ssh">Générer une clé SSH</button> <span id="gires-gen-ssh-status" style="margin-left:8px; color:#6c7075;"></span></p>
                        <p class="description">Le bouton génère une paire de clés et te propose le téléchargement de la clé privée.</p>
                        <p><strong>Clé publique à coller sur OVH</strong> (<code>/home/gires/.ssh/authorized_keys</code>) :</p>
                        <pre id="gires-ssh-public" style="white-space:pre-wrap; background:#f6f7f7; padding:10px; border-radius:6px;"></pre>
                        <p><strong>Bloc à ajouter dans <code>~/.ssh/config</code> :</strong></p>
                        <pre id="gires-ssh-config" style="white-space:pre-wrap; background:#f6f7f7; padding:10px; border-radius:6px;"></pre>
                        <p class="description" style="margin-top:8px;">Clé privée : à garder en local (ne pas partager).</p>
                        <p class="description">Clé publique : à envoyer sur OVH.</p>
                        <p>
                            <a id="gires-ssh-download" href="#" class="button button-primary" style="display:none;">Télécharger la clé privée</a>
                            <a id="gires-ssh-download-pub" href="#" class="button button-secondary" style="display:none;">Télécharger la clé publique</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="db_name">DB name (prod)</label></th>
                    <td><input type="text" id="db_name" name="db_name" value="<?php echo esc_attr($settings['db_name']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="db_user">DB user (prod)</label></th>
                    <td><input type="text" id="db_user" name="db_user" value="<?php echo esc_attr($settings['db_user']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="db_pass">DB pass (prod)</label></th>
                    <td><input type="password" id="db_pass" name="db_pass" value="<?php echo esc_attr($settings['db_pass']); ?>" class="regular-text" autocomplete="new-password"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="db_host">DB host (prod)</label></th>
                    <td><input type="text" id="db_host" name="db_host" value="<?php echo esc_attr($settings['db_host']); ?>" class="regular-text"></td>
                </tr>
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
                var sshCard = document.getElementById('gires-ssh-card');
                var sshIcon = document.getElementById('gires-ssh-icon');
                var sshStatusText = document.getElementById('gires-ssh-status');
                var genSshBtn = document.getElementById('gires-gen-ssh');
                var genSshStatus = document.getElementById('gires-gen-ssh-status');
                var sshPublic = document.getElementById('gires-ssh-public');
                var sshConfig = document.getElementById('gires-ssh-config');
                var sshDownload = document.getElementById('gires-ssh-download');
                var sshDownloadPub = document.getElementById('gires-ssh-download-pub');
                var testSshBtn = document.getElementById('gires-test-ssh');
                var testSshStatus = document.getElementById('gires-test-ssh-status');
                var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var nonce = '<?php echo esc_js(wp_create_nonce('gires_cicd_job')); ?>';
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

                function setSshCard(type, message) {
                    if (!sshCard || !sshIcon || !sshStatusText) return;
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
                    sshIcon.style.background = bg;
                    sshIcon.textContent = symbol;
                    sshStatusText.textContent = message;
                    sshStatusText.style.color = color;
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
                    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: form, credentials: 'same-origin' })
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

                function autoTestSsh() {
                    var host = document.getElementById('ssh_host');
                    var user = document.getElementById('ssh_user');
                    var path = document.getElementById('ssh_path');
                    if (!host || !user || !path) return;
                    if (!host.value || !user.value || !path.value) return;
                    setSshCard('pending', 'Test en cours...');
                    var form = new FormData();
                    form.append('action', 'gires_cicd_test_ssh');
                    form.append('_ajax_nonce', nonce);
                    fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                        .then(function(r) {
                            return r.json().catch(function() {
                                throw new Error('json');
                            });
                        })
                        .then(function(data) {
                            if (!data || !data.success) {
                                setSshCard('error', data && data.data ? data.data.message : 'Connexion SSH impossible.');
                                return;
                            }
                            setSshCard('success', data.data.message || 'Connexion SSH OK.');
                        })
                        .catch(function() {
                            setSshCard('error', 'Connexion SSH impossible.');
                        });
                }

                autoTestSsh();

                if (genSshBtn) {
                    genSshBtn.addEventListener('click', function() {
                        if (genSshStatus) genSshStatus.textContent = 'Démarrage...';
                        if (sshPublic) sshPublic.textContent = '';
                        if (sshConfig) sshConfig.textContent = '';
                        if (sshDownload) sshDownload.style.display = 'none';
                        if (sshDownloadPub) sshDownloadPub.style.display = 'none';
                        var form = new FormData();
                        form.append('action', 'gires_cicd_generate_ssh_key');
                        form.append('_ajax_nonce', nonce);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(function(r) {
                                return r.json().catch(function() {
                                    throw new Error('json');
                                });
                            })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    if (genSshStatus) genSshStatus.textContent = data && data.data ? data.data.message : 'Impossible de générer la clé.';
                                    return;
                                }
                                if (sshPublic) sshPublic.textContent = data.data.public_key || '';
                                if (sshConfig) sshConfig.textContent = data.data.config_snippet || '';
                                if (sshDownload && data.data.download_private) {
                                    sshDownload.href = data.data.download_private;
                                    sshDownload.style.display = 'inline-block';
                                }
                                if (sshDownloadPub && data.data.download_public) {
                                    sshDownloadPub.href = data.data.download_public;
                                    sshDownloadPub.style.display = 'inline-block';
                                }
                                if (genSshStatus) genSshStatus.textContent = 'Clé générée.';
                            })
                            .catch(function() {
                                if (genSshStatus) genSshStatus.textContent = 'Impossible de générer la clé.';
                            });
                    });
                }

                if (testSshBtn) {
                    testSshBtn.addEventListener('click', function() {
                        if (testSshStatus) testSshStatus.textContent = 'Test en cours...';
                        setSshCard('pending', 'Test en cours...');
                        var form = new FormData();
                        form.append('action', 'gires_cicd_test_ssh');
                        form.append('_ajax_nonce', nonce);
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(function(r) {
                                return r.json().catch(function() {
                                    throw new Error('json');
                                });
                            })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    if (testSshStatus) testSshStatus.textContent = data && data.data ? data.data.message : 'Connexion SSH impossible.';
                                    setSshCard('error', data && data.data ? data.data.message : 'Connexion SSH impossible.');
                                    return;
                                }
                                if (testSshStatus) testSshStatus.textContent = data.data.message || 'Connexion SSH OK.';
                                setSshCard('success', data.data.message || 'Connexion SSH OK.');
                            })
                            .catch(function() {
                                if (testSshStatus) testSshStatus.textContent = 'Connexion SSH impossible.';
                                setSshCard('error', 'Connexion SSH impossible.');
                            });
                    });
                }
            })();
        </script>
    <?php endif; ?>
</div>
