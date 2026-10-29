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
        navigationItems: [],
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

        this.navigationItems[target].focus();
      }
    },
    render() {
      const buttons = this.items.map((item, index) => global.Vue.h('button', {
        key: item.id,
        ref: element => {
          if (element) {
            this.navigationItems[index] = element;
          }
        },
        type: 'button',
        class: { active: this.view === item.id },
        'aria-current': this.view === item.id ? 'page' : null,
        onClick: () => this.$emit('navigate', item.id),
        onKeydown: event => this.moveFocus(event, index)
      }, item.label));

      buttons.push(global.Vue.h('button', {
        type: 'button',
        class: 'primary',
        onClick: () => this.$emit('logout')
      }, 'Logout'));

      return global.Vue.h('nav', {
        class: 'sidebar',
        'aria-label': 'Primary navigation'
      }, buttons);
    }
  };
}(window));
