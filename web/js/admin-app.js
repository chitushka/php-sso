(function (global) {
  'use strict';

  const app = global.Vue.createApp({
    render: global.AdminRender,
    mixins: [
      global.AdminCore,
      global.AdminAuth,
      global.AdminOauth,
      global.AdminAccess,
      global.AdminProviders,
      global.AdminOperations
    ]
  });

  app.component('admin-navigation', global.AdminNavigation);
  app.config.errorHandler = (error, instance) => {
    if (instance) {
      instance.errorMessage = global.AdminUi.errorMessage(error);
    }
  };
  app.mount('#app');
}(window));
