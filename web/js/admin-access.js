(function (global) {
  'use strict';

  global.AdminAccess = {
    data() {
      return {
        users: [],
        roles: [],
        permissions: [],
        groups: [],
        selectedUser: null,
        selectedUserRoles: [],
        selectedUserGroups: [],
        selectedUserIdentities: [],
        selectedRole: null,
        selectedRolePermissions: [],
        selectedGroup: null,
        selectedGroupRoles: [],
        assignmentForms: {
          userRoleId: '',
          userGroupId: '',
          rolePermissionId: '',
          groupRoleId: ''
        },
        userForm: { username: '', email: '', password: '' },
        roleForm: { code: '', name: '', description: '' },
        groupForm: { code: '', name: '', description: '' },
      };
    },
    computed: {
      availableUserRoles() {
        const assigned = new Set(this.selectedUserRoles.map(item => item.id));
        return this.roles.filter(item => !assigned.has(item.id));
      },
      availableUserGroups() {
        const assigned = new Set(this.selectedUserGroups.map(item => item.id));
        return this.groups.filter(item => !assigned.has(item.id));
      },
      availableRolePermissions() {
        const assigned = new Set(this.selectedRolePermissions.map(item => item.id));
        return this.permissions.filter(item => !assigned.has(item.id));
      },
      availableGroupRoles() {
        const assigned = new Set(this.selectedGroupRoles.map(item => item.id));
        return this.roles.filter(item => !assigned.has(item.id));
      }
    },
    methods: {
      async createUser() {
        const data = await this.api('/api/v1/users', { method: 'POST', body: JSON.stringify(this.userForm) });
        this.message = 'User created: ' + data.username;
        this.userForm = { username: '', email: '', password: '' };
        await this.refresh();
      },
      async createRole() {
        const data = await this.api('/api/v1/roles', { method: 'POST', body: JSON.stringify(this.roleForm) });
        this.message = 'Role created: ' + data.code;
        this.roleForm = { code: '', name: '', description: '' };
        await this.refresh();
      },
      async createGroup() {
        const data = await this.api('/api/v1/groups', { method: 'POST', body: JSON.stringify(this.groupForm) });
        this.message = 'Group created: ' + data.code;
        this.groupForm = { code: '', name: '', description: '' };
        await this.refresh();
      },
      async selectUser(user) {
        this.selectedUser = user;
        await this.loadUserAssignments();
      },
      async loadUserAssignments() {
        if (!this.selectedUser) {
          return;
        }
  
        this.selectedUserRoles = await this.api('/api/v1/users/' + this.selectedUser.id + '/roles');
        this.selectedUserGroups = await this.api('/api/v1/users/' + this.selectedUser.id + '/groups');
        this.selectedUserIdentities = await this.api('/api/v1/users/' + this.selectedUser.id + '/federated-identities');
      },
      async assignUserRole() {
        if (!this.selectedUser || !this.assignmentForms.userRoleId) {
          return;
        }
  
        await this.api('/api/v1/users/' + this.selectedUser.id + '/roles', {
          method: 'POST',
          body: JSON.stringify({ role_id: this.assignmentForms.userRoleId })
        });
        this.assignmentForms.userRoleId = '';
        this.message = 'User role assigned';
        await this.loadUserAssignments();
      },
      async removeUserRole(roleId) {
        if (!this.selectedUser) {
          return;
        }
        const role = this.selectedUserRoles.find(item => item.id === roleId);
        if (!this.confirmAction('Remove role ' + (role ? role.code : roleId) + ' from ' + this.selectedUser.username + '?')) {
          return;
        }
  
        await this.api('/api/v1/users/' + this.selectedUser.id + '/roles/' + roleId, { method: 'DELETE' });
        this.message = 'User role removed';
        await this.loadUserAssignments();
      },
      async assignUserGroup() {
        if (!this.selectedUser || !this.assignmentForms.userGroupId) {
          return;
        }
  
        await this.api('/api/v1/users/' + this.selectedUser.id + '/groups', {
          method: 'POST',
          body: JSON.stringify({ group_id: this.assignmentForms.userGroupId })
        });
        this.assignmentForms.userGroupId = '';
        this.message = 'User group assigned';
        await this.loadUserAssignments();
      },
      async removeUserGroup(groupId) {
        if (!this.selectedUser) {
          return;
        }
        const group = this.selectedUserGroups.find(item => item.id === groupId);
        if (!this.confirmAction('Remove ' + this.selectedUser.username + ' from group ' + (group ? group.code : groupId) + '?')) {
          return;
        }
  
        await this.api('/api/v1/users/' + this.selectedUser.id + '/groups/' + groupId, { method: 'DELETE' });
        this.message = 'User group removed';
        await this.loadUserAssignments();
      },
      async unlinkFederatedIdentity(identity) {
        if (!this.selectedUser) return;
        if (!this.confirmAction('Unlink the ' + identity.provider_name + ' identity from ' + this.selectedUser.username + '?')) {
          return;
        }
        await this.api('/api/v1/users/' + this.selectedUser.id + '/federated-identities', {
          method: 'DELETE',
          body: JSON.stringify({
            provider_id: identity.provider_id,
            external_subject: identity.external_subject
          })
        });
        this.message = 'Federated identity unlinked';
        await this.loadUserAssignments();
      },
      async selectRole(role) {
        this.selectedRole = role;
        await this.loadRolePermissions();
      },
      async loadRolePermissions() {
        if (!this.selectedRole) {
          return;
        }
  
        this.selectedRolePermissions = await this.api('/api/v1/roles/' + this.selectedRole.id + '/permissions');
      },
      async assignRolePermission() {
        if (!this.selectedRole || !this.assignmentForms.rolePermissionId) {
          return;
        }
  
        await this.api('/api/v1/roles/' + this.selectedRole.id + '/permissions', {
          method: 'POST',
          body: JSON.stringify({ permission_id: this.assignmentForms.rolePermissionId })
        });
        this.assignmentForms.rolePermissionId = '';
        this.message = 'Role permission assigned';
        await this.loadRolePermissions();
      },
      async removeRolePermission(permissionId) {
        if (!this.selectedRole) {
          return;
        }
        const permission = this.selectedRolePermissions.find(item => item.id === permissionId);
        const permissionName = permission ? permission.resource + ':' + permission.action : permissionId;
        if (!this.confirmAction('Remove permission ' + permissionName + ' from role ' + this.selectedRole.code + '?')) {
          return;
        }
  
        await this.api('/api/v1/roles/' + this.selectedRole.id + '/permissions/' + permissionId, { method: 'DELETE' });
        this.message = 'Role permission removed';
        await this.loadRolePermissions();
      },
      async selectGroup(group) {
        this.selectedGroup = group;
        await this.loadGroupRoles();
      },
      async loadGroupRoles() {
        if (!this.selectedGroup) {
          return;
        }
  
        this.selectedGroupRoles = await this.api('/api/v1/groups/' + this.selectedGroup.id + '/roles');
      },
      async assignGroupRole() {
        if (!this.selectedGroup || !this.assignmentForms.groupRoleId) {
          return;
        }
  
        await this.api('/api/v1/groups/' + this.selectedGroup.id + '/roles', {
          method: 'POST',
          body: JSON.stringify({ role_id: this.assignmentForms.groupRoleId })
        });
        this.assignmentForms.groupRoleId = '';
        this.message = 'Group role assigned';
        await this.loadGroupRoles();
      },
      async removeGroupRole(roleId) {
        if (!this.selectedGroup) {
          return;
        }
        const role = this.selectedGroupRoles.find(item => item.id === roleId);
        if (!this.confirmAction('Remove role ' + (role ? role.code : roleId) + ' from group ' + this.selectedGroup.code + '?')) {
          return;
        }
  
        await this.api('/api/v1/groups/' + this.selectedGroup.id + '/roles/' + roleId, { method: 'DELETE' });
        this.message = 'Group role removed';
        await this.loadGroupRoles();
      },
      async reloadSelectedAssignments() {
        if (this.selectedUser) {
          const freshUser = this.users.find(item => item.id === this.selectedUser.id);
          this.selectedUser = freshUser || null;
          if (this.selectedUser) {
            await this.loadUserAssignments();
          }
        }
  
        if (this.selectedRole) {
          const freshRole = this.roles.find(item => item.id === this.selectedRole.id);
          this.selectedRole = freshRole || null;
          if (this.selectedRole) {
            await this.loadRolePermissions();
          }
        }
  
        if (this.selectedGroup) {
          const freshGroup = this.groups.find(item => item.id === this.selectedGroup.id);
          this.selectedGroup = freshGroup || null;
          if (this.selectedGroup) {
            await this.loadGroupRoles();
          }
        }
      },
    }
  };
}(window));
