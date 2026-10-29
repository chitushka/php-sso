(function (global) {
  'use strict';

  global.AdminUi = {
    confirmAction(message) {
      return global.confirm(message);
    },
    errorMessage(error) {
      if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message;
      }
      return 'Unexpected request failure';
    }
  };
}(window));
