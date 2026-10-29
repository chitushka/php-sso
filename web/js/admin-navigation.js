(function (global) {
  'use strict';

  global.AdminNavigation = {
    props: {
      view: {
        type: String,
        required: true
      }
    },
    emits: ['navigate', 'logout'],
    data() {
      return {
        items: [
          { id: 'dashboard', label: 'Dashboard' },
          { id: 'bootstrap', label: 'Bootstrap' },
          { id: 'login', label: 'Login' },
          { id: 'clients', label: 'OAuth Clients' },
          { id: 'users', label: 'Users' },
          { id: 'roles', label: 'Roles' },
          { id: 'groups', label: 'Groups' },
          { id: 'ldap', label: 'LDAP' },
          { id: 'identity', label: 'Federation' },
          { id: 'operations', label: 'Operations' },
          { id: 'audit', label: 'Audit' }
        ]
      };
    },
    methods: {
      moveFocus(event, index) {
        const keys = ['ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft', 'Home', 'End'];
        if (!keys.includes(event.key)) {
          return;
        }

        event.preventDefault();
        let target = index;
        if (event.key === 'Home') {
          target = 0;
        } else if (event.key === 'End') {
          target = this.items.length - 1;
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
          target = (index + 1) % this.items.length;
        } else {
          target = (index - 1 + this.items.length) % this.items.length;
        }

        this.$refs.navigationItems[target].focus();
      }
    },
    template: `
      <nav class="sidebar" aria-label="Primary navigation">
        <button
          v-for="(item, index) in items"
          :key="item.id"
          ref="navigationItems"
          type="button"
          :class="{active: view === item.id}"
          :aria-current="view === item.id ? 'page' : null"
          @click="$emit('navigate', item.id)"
          @keydown="moveFocus($event, index)"
        >{{ item.label }}</button>
        <button type="button" class="primary" @click="$emit('logout')">Logout</button>
      </nav>
    `
  };
}(window));
