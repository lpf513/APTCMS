<?php

declare(strict_types=1);

namespace core;

use PDO;
use Throwable;

final class InstallService
{
    /** @param array<string,mixed> $config */
    public function run(array $config): array
    {
        if (($config['db']['enabled'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'db.enabled=false，未启用数据库安装'];
        }

        try {
            $pdo = DB::conn($config['db']);
            $this->applySchema($pdo, __DIR__ . '/../docs/sql/schema.sql');
            $this->seedArticles($pdo, __DIR__ . '/../modules/article/data.json');
            $this->seedAdminUsers($pdo, __DIR__ . '/../config/admin.php');
            $this->seedRbac($pdo, __DIR__ . '/../config/rbac.php');
            $this->seedModels($pdo, __DIR__ . '/../config/models.php');
            $this->seedSystemSettings($pdo, __DIR__ . '/../config/app.php');
            return ['ok' => true, 'message' => '安装完成'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '安装失败: ' . $e->getMessage()];
        }
    }

    private function applySchema(PDO $pdo, string $file): void
    {
        $sql = (string) file_get_contents($file);
        $chunks = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($chunks as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private function seedArticles(PDO $pdo, string $file): void
    {
        $rows = json_decode((string) file_get_contents($file), true);
        if (!is_array($rows)) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO apt_content_article (slug,title,content,author,published_at,status,created_at,updated_at) VALUES (:slug,:title,:content,:author,:published_at,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), author=VALUES(author), published_at=VALUES(published_at), updated_at=NOW()');
        foreach ($rows as $row) {
            $stmt->execute([
                ':slug' => (string) ($row['slug'] ?? ''),
                ':title' => (string) ($row['title'] ?? ''),
                ':content' => (string) ($row['content'] ?? ''),
                ':author' => (string) ($row['author'] ?? ''),
                ':published_at' => (string) ($row['published_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    private function seedAdminUsers(PDO $pdo, string $file): void
    {
        $config = require $file;
        $users = $config['users'] ?? [];
        if (!is_array($users)) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO apt_admin_user (username,password_hash,role_code,status,created_at,updated_at) VALUES (:username,:password_hash,:role_code,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role_code=VALUES(role_code), status=1, updated_at=NOW()');
        foreach ($users as $username => $user) {
            if (!is_array($user)) {
                continue;
            }
            $stmt->execute([
                ':username' => (string) $username,
                ':password_hash' => (string) ($user['password_hash'] ?? ''),
                ':role_code' => (string) ($user['role'] ?? 'editor'),
            ]);
        }
    }

    private function seedRbac(PDO $pdo, string $file): void
    {
        $config = require $file;
        $roles = $config['roles'] ?? [];
        if (!is_array($roles)) {
            return;
        }

        $pdo->exec('DELETE FROM apt_rbac_role_permission');
        $stmt = $pdo->prepare('INSERT INTO apt_rbac_role_permission (role_code, permission_code) VALUES (:role_code, :permission_code)');
        foreach ($roles as $role => $permissions) {
            if (!is_array($permissions)) {
                continue;
            }
            foreach ($permissions as $permission) {
                $stmt->execute([
                    ':role_code' => (string) $role,
                    ':permission_code' => (string) $permission,
                ]);
            }
        }
    }

    private function seedModels(PDO $pdo, string $file): void
    {
        $config = require $file;
        if (!is_array($config)) {
            return;
        }

        $pdo->exec('DELETE FROM apt_model_field');
        $pdo->exec('DELETE FROM apt_model');

        $modelStmt = $pdo->prepare('INSERT INTO apt_model (model_code,model_name,table_name,module_code,template_list,template_detail,created_at,updated_at) VALUES (:model_code,:model_name,:table_name,:module_code,:template_list,:template_detail,NOW(),NOW())');
        $fieldStmt = $pdo->prepare('INSERT INTO apt_model_field (model_code,field_code,field_name,field_type,required,ai_understand,seo_weight,geo_weight,embedding,sort_order) VALUES (:model_code,:field_code,:field_name,:field_type,:required,:ai_understand,:seo_weight,:geo_weight,:embedding,:sort_order)');

        foreach ($config as $modelCode => $model) {
            if (!is_array($model)) {
                continue;
            }

            $modelStmt->execute([
                ':model_code' => (string) $modelCode,
                ':model_name' => (string) ($model['name'] ?? ''),
                ':table_name' => (string) ($model['table'] ?? ''),
                ':module_code' => (string) ($model['module'] ?? ''),
                ':template_list' => (string) (($model['template']['list'] ?? '')),
                ':template_detail' => (string) (($model['template']['detail'] ?? '')),
            ]);

            $sort = 0;
            $fields = $model['fields'] ?? [];
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldCode => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $sort++;
                $fieldStmt->execute([
                    ':model_code' => (string) $modelCode,
                    ':field_code' => (string) $fieldCode,
                    ':field_name' => (string) ($field['name'] ?? (string) $fieldCode),
                    ':field_type' => (string) ($field['type'] ?? 'text'),
                    ':required' => ((bool) ($field['required'] ?? false)) ? 1 : 0,
                    ':ai_understand' => ((bool) ($field['ai_understand'] ?? false)) ? 1 : 0,
                    ':seo_weight' => (int) ($field['seo_weight'] ?? 0),
                    ':geo_weight' => (int) ($field['geo_weight'] ?? 0),
                    ':embedding' => ((bool) ($field['embedding'] ?? false)) ? 1 : 0,
                    ':sort_order' => $sort,
                ]);
            }
        }
    }


    private function seedSystemSettings(PDO $pdo, string $file): void
    {
        $config = require $file;
        if (!is_array($config)) {
            return;
        }

        $candidates = [
            'name' => $config['name'] ?? 'APTCMS',
            'version' => $config['version'] ?? '1.0.0',
            'base_url' => $config['base_url'] ?? '',
            'api.default_page_size' => $config['api']['default_page_size'] ?? 20,
            'api.max_page_size' => $config['api']['max_page_size'] ?? 100,
            'api.rate_limit' => $config['api']['rate_limit'] ?? 120,
            'api.rate_window' => $config['api']['rate_window'] ?? 60,
            'security.login_rate_limit' => $config['security']['login_rate_limit'] ?? 5,
            'security.login_rate_window' => $config['security']['login_rate_window'] ?? 300,
            'security.admin_ip_allowlist' => $config['security']['admin_ip_allowlist'] ?? [],
        ];

        $stmt = $pdo->prepare('INSERT INTO apt_system_setting (key_name, value_json, updated_at) VALUES (:key_name, :value_json, NOW()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json), updated_at=NOW()');
        foreach ($candidates as $key => $value) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                continue;
            }
            $stmt->execute([
                ':key_name' => (string) $key,
                ':value_json' => $json,
            ]);
        }
    }

}
