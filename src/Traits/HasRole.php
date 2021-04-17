<?php

namespace Yajra\Acl\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Yajra\Acl\Models\Role;

/**
 * @property \Illuminate\Database\Eloquent\Collection roles
 * @method static Builder havingRoles(array $roleIds)
 * @method static Builder havingRolesBySlugs(array $slugs)
 */
trait HasRole
{
    private $roleClass;

    /**
     * Check if user have access using any of the acl.
     *
     * @param  array  $acl
     * @return boolean
     */
    public function canAccess(array $acl): bool
    {
        return $this->canAtLeast($acl) || $this->hasRole($acl);
    }

    /**
     * Check if user has at least one of the given permissions
     *
     * @param  array  $permissions
     * @return bool
     */
    public function canAtLeast(array $permissions): bool
    {
        $can = false;

        if (auth()->check()) {
            foreach ($this->roles as $role) {
                if ($role->canAtLeast($permissions)) {
                    $can = true;
                }
            }
        } else {
            $guest = $this->getRoleClass()->whereSlug('guest')->first();

            if ($guest) {
                return $guest->canAtLeast($permissions);
            }
        }

        return $can;
    }

    /**
     * Get Role class.
     *
     * @return Role
     */
    public function getRoleClass(): Role
    {
        if (!isset($this->roleClass)) {
            $this->roleClass = resolve(config('acl.role'));
        }

        return $this->roleClass;
    }

    /**
     * Check if user has the given role.
     *
     * @param  string|array  $role
     * @return bool
     */
    public function hasRole($role): bool
    {
        if (is_string($role)) {
            return $this->roles->contains('slug', $role);
        }

        if (is_array($role)) {
            $roles = $this->getRoleSlugs();

            $intersection = array_intersect($roles, (array) $role);
            $intersectionCount = count($intersection);

            return $intersectionCount > 0;
        }

        return !!$role->intersect($this->roles)->count();
    }

    /**
     * Get all user roles.
     *
     * @return array|null
     */
    public function getRoleSlugs()
    {
        if (!is_null($this->roles)) {
            return $this->roles->pluck('slug')->toArray();
        }

        return null;
    }

    /**
     * Attach a role to user using slug.
     *
     * @param $slug
     * @return bool
     */
    public function attachRoleBySlug($slug): bool
    {
        $role = $this->getRoleClass()->where('slug', $slug)->first();

        return $this->attachRole($role);
    }

    /**
     * Attach a role to user
     *
     * @param  Role  $role
     * @return boolean
     */
    public function attachRole(Role $role): bool
    {
        return $this->assignRole($role->id);
    }

    /**
     * Assigns the given role to the user.
     *
     * @param  int  $roleId
     * @return bool
     */
    public function assignRole($roleId = null): bool
    {
        $roles = $this->roles;

        if (!$roles->contains($roleId)) {
            $this->roles()->attach($roleId);

            return true;
        }

        return false;
    }

    /**
     * Model can have many roles.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(config('acl.role', Role::class))->withTimestamps();
    }

    /**
     * Query scope for user having the given roles.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $roles
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHavingRoles(Builder $query, array $roles): Builder
    {
        return $query->whereExists(function ($query) use ($roles) {
            $query->selectRaw('1')
                ->from('role_user')
                ->whereRaw('role_user.user_id = users.id')
                ->whereIn('role_id', $roles);
        });
    }

    /**
     * Query scope for user having the given roles by slugs.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $slugs
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHavingRolesBySlugs(Builder $query, array $slugs): Builder
    {
        return $query->whereHas('roles', function ($query) use ($slugs) {
            $query->whereIn('roles.slug', $slugs);
        });
    }

    /**
     * Revokes the given role from the user using slug.
     *
     * @param  string  $slug
     * @return bool
     */
    public function revokeRoleBySlug($slug): bool
    {
        $role = $this->getRoleClass()->where('slug', $slug)->first();

        return $this->roles()->detach($role);
    }

    /**
     * Revokes the given role from the user.
     *
     * @param  mixed  $role
     * @return bool
     */
    public function revokeRole($role = ""): bool
    {
        return $this->roles()->detach($role);
    }

    /**
     * Syncs the given role(s) with the user.
     *
     * @param  array  $roles
     * @return array
     */
    public function syncRoles(array $roles): array
    {
        return $this->roles()->sync($roles);
    }

    /**
     * Revokes all roles from the user.
     *
     * @return bool
     */
    public function revokeAllRoles(): bool
    {
        return $this->roles()->detach();
    }

    /**
     * Get all user role permissions.
     *
     * @return array
     */
    public function getPermissions(): array
    {
        $permissions = [[], []];

        foreach ($this->roles as $role) {
            $permissions[] = $role->getPermissions();
        }

        return call_user_func_array('array_merge', $permissions);
    }

    /**
     * Magic __call method to handle dynamic methods.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($method, $arguments = [])
    {
        // Handle isRoleSlug() methods
        if (Str::startsWith($method, 'is') and $method !== 'is') {
            $role = substr($method, 2);

            return $this->isRole($role);
        }

        // Handle canDoSomething() methods
        if (Str::startsWith($method, 'can') and $method !== 'can') {
            $permission = substr($method, 3);

            return $this->can($permission);
        }

        return parent::__call($method, $arguments);
    }

    /**
     * Checks if the user has the given role.
     *
     * @param  string  $slug
     * @return bool
     */
    public function isRole(string $slug): bool
    {
        $slug = Str::lower($slug);

        foreach ($this->roles as $role) {
            if ($role->slug == $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the given entity/model is owned by the user.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $entity
     * @param  string  $relation
     * @return bool
     */
    public function owns(Model $entity, $relation = 'user_id'): bool
    {
        return $this->getKeyName() === $entity->{$relation};
    }
}
