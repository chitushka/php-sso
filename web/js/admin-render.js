(function (global) {
  'use strict';

  global.AdminRender = (function (Vue) {
    const { createElementVNode: _createElementVNode, toDisplayString: _toDisplayString, resolveComponent: _resolveComponent, createVNode: _createVNode, openBlock: _openBlock, createElementBlock: _createElementBlock, createCommentVNode: _createCommentVNode, vModelText: _vModelText, withDirectives: _withDirectives, renderList: _renderList, Fragment: _Fragment, vModelSelect: _vModelSelect, createTextVNode: _createTextVNode, vModelCheckbox: _vModelCheckbox } = Vue
    
    const _hoisted_1 = { class: "topbar" }
    const _hoisted_2 = { "aria-live": "polite" }
    const _hoisted_3 = { class: "shell" }
    const _hoisted_4 = ["aria-busy"]
    const _hoisted_5 = {
      key: 0,
      class: "panel"
    }
    const _hoisted_6 = { class: "grid" }
    const _hoisted_7 = { class: "metric" }
    const _hoisted_8 = { class: "muted" }
    const _hoisted_9 = { class: "metric" }
    const _hoisted_10 = { class: "muted" }
    const _hoisted_11 = { class: "metric" }
    const _hoisted_12 = { class: "muted" }
    const _hoisted_13 = { class: "metric" }
    const _hoisted_14 = { class: "muted" }
    const _hoisted_15 = { class: "metric" }
    const _hoisted_16 = { class: "muted" }
    const _hoisted_17 = { class: "metric" }
    const _hoisted_18 = { class: "muted" }
    const _hoisted_19 = {
      key: 1,
      class: "panel login"
    }
    const _hoisted_20 = {
      key: 2,
      class: "panel login"
    }
    const _hoisted_21 = { key: 0 }
    const _hoisted_22 = ["onClick"]
    const _hoisted_23 = {
      key: 3,
      class: "panel login"
    }
    const _hoisted_24 = {
      key: 4,
      class: "panel login"
    }
    const _hoisted_25 = {
      key: 5,
      class: "panel login"
    }
    const _hoisted_26 = { class: "muted" }
    const _hoisted_27 = { class: "grid" }
    const _hoisted_28 = { class: "grid" }
    const _hoisted_29 = { class: "muted" }
    const _hoisted_30 = { key: 0 }
    const _hoisted_31 = ["value"]
    const _hoisted_32 = { class: "muted" }
    const _hoisted_33 = ["disabled"]
    const _hoisted_34 = ["disabled"]
    const _hoisted_35 = { key: 3 }
    const _hoisted_36 = {
      key: 4,
      class: "muted"
    }
    const _hoisted_37 = {
      key: 5,
      class: "table-wrap"
    }
    const _hoisted_38 = ["disabled", "aria-label", "onClick"]
    const _hoisted_39 = {
      key: 6,
      class: "panel"
    }
    const _hoisted_40 = { class: "grid" }
    const _hoisted_41 = {
      key: 7,
      class: "panel"
    }
    const _hoisted_42 = { class: "grid" }
    const _hoisted_43 = { class: "table-wrap" }
    const _hoisted_44 = ["aria-label", "onClick"]
    const _hoisted_45 = {
      key: 0,
      class: "assignment"
    }
    const _hoisted_46 = { class: "grid" }
    const _hoisted_47 = { class: "inline-form" }
    const _hoisted_48 = ["value"]
    const _hoisted_49 = { class: "chips" }
    const _hoisted_50 = ["disabled", "aria-label", "onClick"]
    const _hoisted_51 = { class: "inline-form" }
    const _hoisted_52 = ["value"]
    const _hoisted_53 = { class: "chips" }
    const _hoisted_54 = ["disabled", "aria-label", "onClick"]
    const _hoisted_55 = {
      key: 0,
      class: "muted"
    }
    const _hoisted_56 = {
      key: 1,
      class: "table-wrap"
    }
    const _hoisted_57 = ["disabled", "aria-label", "onClick"]
    const _hoisted_58 = {
      key: 8,
      class: "panel"
    }
    const _hoisted_59 = { class: "grid" }
    const _hoisted_60 = { class: "table-wrap" }
    const _hoisted_61 = ["aria-label", "onClick"]
    const _hoisted_62 = {
      key: 0,
      class: "assignment"
    }
    const _hoisted_63 = { class: "inline-form" }
    const _hoisted_64 = ["value"]
    const _hoisted_65 = { class: "chips" }
    const _hoisted_66 = ["disabled", "aria-label", "onClick"]
    const _hoisted_67 = {
      key: 9,
      class: "panel"
    }
    const _hoisted_68 = { class: "grid" }
    const _hoisted_69 = { class: "table-wrap" }
    const _hoisted_70 = ["aria-label", "onClick"]
    const _hoisted_71 = {
      key: 0,
      class: "assignment"
    }
    const _hoisted_72 = { class: "inline-form" }
    const _hoisted_73 = ["value"]
    const _hoisted_74 = { class: "chips" }
    const _hoisted_75 = ["disabled", "aria-label", "onClick"]
    const _hoisted_76 = {
      key: 10,
      class: "panel"
    }
    const _hoisted_77 = {
      key: 11,
      class: "panel"
    }
    const _hoisted_78 = { class: "grid" }
    const _hoisted_79 = { class: "grid" }
    const _hoisted_80 = {
      key: 12,
      class: "panel"
    }
    const _hoisted_81 = { class: "muted" }
    const _hoisted_82 = { class: "grid" }
    const _hoisted_83 = ["disabled"]
    const _hoisted_84 = {
      key: 0,
      class: "grid"
    }
    const _hoisted_85 = { class: "table-wrap" }
    const _hoisted_86 = ["aria-label", "onClick"]
    const _hoisted_87 = ["disabled", "aria-label", "onClick"]
    const _hoisted_88 = {
      key: 13,
      class: "panel"
    }
    const _hoisted_89 = {
      key: 0,
      class: "grid"
    }
    const _hoisted_90 = { class: "metric" }
    const _hoisted_91 = { class: "muted" }
    const _hoisted_92 = { class: "metric" }
    const _hoisted_93 = { class: "muted" }
    const _hoisted_94 = { class: "metric" }
    const _hoisted_95 = { class: "muted" }
    const _hoisted_96 = { class: "metric" }
    const _hoisted_97 = { class: "muted" }
    const _hoisted_98 = { class: "metric" }
    const _hoisted_99 = { class: "muted" }
    const _hoisted_100 = ["disabled"]
    const _hoisted_101 = { class: "table-wrap" }
    const _hoisted_102 = {
      key: 14,
      class: "muted loading-status",
      role: "status"
    }
    const _hoisted_103 = {
      key: 15,
      class: "error-message",
      role: "alert"
    }
    const _hoisted_104 = {
      class: "muted",
      role: "status",
      "aria-live": "polite",
      "aria-atomic": "true"
    }
    
    return function render(_ctx, _cache) {
      const _component_admin_navigation = _resolveComponent("admin-navigation")
    
      return (_openBlock(), _createElementBlock(_Fragment, null, [
        _cache[197] || (_cache[197] = _createElementVNode("a", {
          class: "skip-link",
          href: "#admin-content"
        }, "Skip to main content", -1 /* CACHED */)),
        _createElementVNode("header", _hoisted_1, [
          _cache[86] || (_cache[86] = _createElementVNode("div", { class: "brand" }, "SSO Admin", -1 /* CACHED */)),
          _createElementVNode("div", _hoisted_2, _toDisplayString(_ctx.user ? _ctx.user.username : 'not signed in'), 1 /* TEXT */)
        ]),
        _createElementVNode("main", _hoisted_3, [
          _createVNode(_component_admin_navigation, {
            view: _ctx.view,
            onNavigate: _ctx.navigate,
            onLogout: _ctx.logout
          }, null, 8 /* PROPS */, ["view", "onNavigate", "onLogout"]),
          _createElementVNode("section", {
            id: "admin-content",
            ref: "adminContent",
            class: "content",
            tabindex: "-1",
            "aria-busy": _ctx.isBusy ? 'true' : 'false'
          }, [
            (_ctx.view === 'dashboard')
              ? (_openBlock(), _createElementBlock("div", _hoisted_5, [
                  _cache[93] || (_cache[93] = _createElementVNode("h1", null, "Dashboard", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_6, [
                    _createElementVNode("div", _hoisted_7, [
                      _cache[87] || (_cache[87] = _createElementVNode("strong", null, "Bootstrap", -1 /* CACHED */)),
                      _createElementVNode("p", _hoisted_8, _toDisplayString(_ctx.bootstrap.initialized ? 'initialized' : 'not initialized'), 1 /* TEXT */)
                    ]),
                    _createElementVNode("div", _hoisted_9, [
                      _cache[88] || (_cache[88] = _createElementVNode("strong", null, "Issuer", -1 /* CACHED */)),
                      _createElementVNode("p", _hoisted_10, _toDisplayString(_ctx.discovery.issuer || 'loading'), 1 /* TEXT */)
                    ]),
                    _createElementVNode("div", _hoisted_11, [
                      _cache[89] || (_cache[89] = _createElementVNode("strong", null, "Clients", -1 /* CACHED */)),
                      _createElementVNode("p", _hoisted_12, _toDisplayString(_ctx.clients.length), 1 /* TEXT */)
                    ]),
                    _createElementVNode("div", _hoisted_13, [
                      _cache[90] || (_cache[90] = _createElementVNode("strong", null, "Users", -1 /* CACHED */)),
                      _createElementVNode("p", _hoisted_14, _toDisplayString(_ctx.users.length), 1 /* TEXT */)
                    ]),
                    _createElementVNode("div", _hoisted_15, [
                      _cache[91] || (_cache[91] = _createElementVNode("strong", null, "Roles", -1 /* CACHED */)),
                      _createElementVNode("p", _hoisted_16, _toDisplayString(_ctx.roles.length), 1 /* TEXT */)
                    ]),
                    _createElementVNode("div", _hoisted_17, [
                      _cache[92] || (_cache[92] = _createElementVNode("strong", null, "LDAP providers", -1 /* CACHED */)),
                      _createElementVNode("p", _hoisted_18, _toDisplayString(_ctx.ldapProviders.length), 1 /* TEXT */)
                    ])
                  ])
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'bootstrap')
              ? (_openBlock(), _createElementBlock("div", _hoisted_19, [
                  _cache[94] || (_cache[94] = _createElementVNode("h1", null, "Bootstrap admin", -1 /* CACHED */)),
                  _cache[95] || (_cache[95] = _createElementVNode("label", { for: "bootstrap-username" }, "Username", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "bootstrap-username",
                    "onUpdate:modelValue": _cache[0] || (_cache[0] = $event => ((_ctx.bootstrapForm.username) = $event)),
                    autocomplete: "username"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.bootstrapForm.username]
                  ]),
                  _cache[96] || (_cache[96] = _createElementVNode("label", { for: "bootstrap-email" }, "Email", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "bootstrap-email",
                    type: "email",
                    "onUpdate:modelValue": _cache[1] || (_cache[1] = $event => ((_ctx.bootstrapForm.email) = $event)),
                    autocomplete: "email"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.bootstrapForm.email]
                  ]),
                  _cache[97] || (_cache[97] = _createElementVNode("label", { for: "bootstrap-password" }, "Password", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "bootstrap-password",
                    type: "password",
                    "onUpdate:modelValue": _cache[2] || (_cache[2] = $event => ((_ctx.bootstrapForm.password) = $event)),
                    autocomplete: "new-password"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.bootstrapForm.password]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[3] || (_cache[3] = (...args) => (_ctx.createAdmin && _ctx.createAdmin(...args)))
                  }, "Create admin")
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'login')
              ? (_openBlock(), _createElementBlock("div", _hoisted_20, [
                  _cache[103] || (_cache[103] = _createElementVNode("h1", null, "Login", -1 /* CACHED */)),
                  (!_ctx.mfaChallenge)
                    ? (_openBlock(), _createElementBlock(_Fragment, { key: 0 }, [
                        _cache[99] || (_cache[99] = _createElementVNode("label", { for: "login-username" }, "Username or email", -1 /* CACHED */)),
                        _withDirectives(_createElementVNode("input", {
                          id: "login-username",
                          "onUpdate:modelValue": _cache[4] || (_cache[4] = $event => ((_ctx.loginForm.username) = $event)),
                          autocomplete: "username"
                        }, null, 512 /* NEED_PATCH */), [
                          [_vModelText, _ctx.loginForm.username]
                        ]),
                        _cache[100] || (_cache[100] = _createElementVNode("label", { for: "login-password" }, "Password", -1 /* CACHED */)),
                        _withDirectives(_createElementVNode("input", {
                          id: "login-password",
                          type: "password",
                          "onUpdate:modelValue": _cache[5] || (_cache[5] = $event => ((_ctx.loginForm.password) = $event)),
                          autocomplete: "current-password"
                        }, null, 512 /* NEED_PATCH */), [
                          [_vModelText, _ctx.loginForm.password]
                        ]),
                        _createElementVNode("button", {
                          class: "primary",
                          onClick: _cache[6] || (_cache[6] = (...args) => (_ctx.login && _ctx.login(...args)))
                        }, "Login"),
                        _createElementVNode("button", {
                          onClick: _cache[7] || (_cache[7] = $event => (_ctx.view = 'forgot'))
                        }, "Forgot password"),
                        (_ctx.brokerProviders.length)
                          ? (_openBlock(), _createElementBlock("div", _hoisted_21, [
                              _cache[98] || (_cache[98] = _createElementVNode("p", { class: "muted" }, "External sign-in", -1 /* CACHED */)),
                              (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.brokerProviders, (provider) => {
                                return (_openBlock(), _createElementBlock("button", {
                                  key: provider.code,
                                  onClick: $event => (_ctx.startBrokerLogin(provider.code))
                                }, " Continue with " + _toDisplayString(provider.name), 9 /* TEXT, PROPS */, _hoisted_22))
                              }), 128 /* KEYED_FRAGMENT */))
                            ]))
                          : _createCommentVNode("v-if", true)
                      ], 64 /* STABLE_FRAGMENT */))
                    : (_openBlock(), _createElementBlock(_Fragment, { key: 1 }, [
                        _cache[101] || (_cache[101] = _createElementVNode("p", { class: "muted" }, "Enter TOTP or recovery code.", -1 /* CACHED */)),
                        _cache[102] || (_cache[102] = _createElementVNode("label", { for: "login-mfa-code" }, "MFA code", -1 /* CACHED */)),
                        _withDirectives(_createElementVNode("input", {
                          id: "login-mfa-code",
                          "onUpdate:modelValue": _cache[8] || (_cache[8] = $event => ((_ctx.mfaLoginForm.code) = $event)),
                          inputmode: "numeric",
                          autocomplete: "one-time-code"
                        }, null, 512 /* NEED_PATCH */), [
                          [_vModelText, _ctx.mfaLoginForm.code]
                        ]),
                        _createElementVNode("button", {
                          class: "primary",
                          onClick: _cache[9] || (_cache[9] = (...args) => (_ctx.verifyMfaLogin && _ctx.verifyMfaLogin(...args)))
                        }, "Verify MFA"),
                        _createElementVNode("button", {
                          onClick: _cache[10] || (_cache[10] = $event => (_ctx.mfaChallenge = ''))
                        }, "Cancel")
                      ], 64 /* STABLE_FRAGMENT */))
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'forgot')
              ? (_openBlock(), _createElementBlock("div", _hoisted_23, [
                  _cache[104] || (_cache[104] = _createElementVNode("h1", null, "Forgot password", -1 /* CACHED */)),
                  _cache[105] || (_cache[105] = _createElementVNode("label", { for: "forgot-login" }, "Username or email", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "forgot-login",
                    "onUpdate:modelValue": _cache[11] || (_cache[11] = $event => ((_ctx.forgotForm.login) = $event)),
                    autocomplete: "username"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.forgotForm.login]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[12] || (_cache[12] = (...args) => (_ctx.forgotPassword && _ctx.forgotPassword(...args)))
                  }, "Send reset link"),
                  _createElementVNode("button", {
                    onClick: _cache[13] || (_cache[13] = $event => (_ctx.view = 'login'))
                  }, "Back to login")
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'reset')
              ? (_openBlock(), _createElementBlock("div", _hoisted_24, [
                  _cache[106] || (_cache[106] = _createElementVNode("h1", null, "Reset password", -1 /* CACHED */)),
                  _cache[107] || (_cache[107] = _createElementVNode("label", { for: "reset-token" }, "Token", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "reset-token",
                    "onUpdate:modelValue": _cache[14] || (_cache[14] = $event => ((_ctx.resetForm.token) = $event)),
                    autocomplete: "off"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.resetForm.token]
                  ]),
                  _cache[108] || (_cache[108] = _createElementVNode("label", { for: "reset-password" }, "New password", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "reset-password",
                    type: "password",
                    "onUpdate:modelValue": _cache[15] || (_cache[15] = $event => ((_ctx.resetForm.password) = $event)),
                    autocomplete: "new-password"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.resetForm.password]
                  ]),
                  _cache[109] || (_cache[109] = _createElementVNode("label", { for: "reset-password-confirm" }, "Repeat password", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "reset-password-confirm",
                    type: "password",
                    "onUpdate:modelValue": _cache[16] || (_cache[16] = $event => ((_ctx.resetForm.confirm) = $event)),
                    autocomplete: "new-password"
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.resetForm.confirm]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[17] || (_cache[17] = (...args) => (_ctx.resetPassword && _ctx.resetPassword(...args)))
                  }, "Save password"),
                  _createElementVNode("button", {
                    onClick: _cache[18] || (_cache[18] = $event => (_ctx.view = 'login'))
                  }, "Back to login")
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'dashboard' && _ctx.user)
              ? (_openBlock(), _createElementBlock("div", _hoisted_25, [
                  _cache[120] || (_cache[120] = _createElementVNode("h2", null, "Account", -1 /* CACHED */)),
                  _createElementVNode("p", _hoisted_26, _toDisplayString(_ctx.user.email) + " · " + _toDisplayString(_ctx.user.email_verified ? 'email verified' : 'email not verified'), 1 /* TEXT */),
                  (!_ctx.user.email_verified)
                    ? (_openBlock(), _createElementBlock("button", {
                        key: 0,
                        onClick: _cache[19] || (_cache[19] = (...args) => (_ctx.requestEmailVerification && _ctx.requestEmailVerification(...args)))
                      }, "Send verification email"))
                    : _createCommentVNode("v-if", true),
                  _createElementVNode("div", _hoisted_27, [
                    _createElementVNode("div", null, [
                      _cache[110] || (_cache[110] = _createElementVNode("label", { for: "profile-first-name" }, "First name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "profile-first-name",
                        "onUpdate:modelValue": _cache[20] || (_cache[20] = $event => ((_ctx.profileForm.first_name) = $event)),
                        autocomplete: "given-name"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.profileForm.first_name]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[111] || (_cache[111] = _createElementVNode("label", { for: "profile-last-name" }, "Last name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "profile-last-name",
                        "onUpdate:modelValue": _cache[21] || (_cache[21] = $event => ((_ctx.profileForm.last_name) = $event)),
                        autocomplete: "family-name"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.profileForm.last_name]
                      ])
                    ])
                  ]),
                  _cache[121] || (_cache[121] = _createElementVNode("label", { for: "profile-attributes" }, "Attributes JSON", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("textarea", {
                    id: "profile-attributes",
                    rows: "3",
                    "onUpdate:modelValue": _cache[22] || (_cache[22] = $event => ((_ctx.profileForm.attributesJson) = $event))
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.profileForm.attributesJson]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[23] || (_cache[23] = (...args) => (_ctx.updateProfile && _ctx.updateProfile(...args)))
                  }, "Save profile"),
                  _cache[122] || (_cache[122] = _createElementVNode("h2", null, "Password", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_28, [
                    _createElementVNode("div", null, [
                      _cache[112] || (_cache[112] = _createElementVNode("label", { for: "current-password" }, "Current password", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "current-password",
                        type: "password",
                        "onUpdate:modelValue": _cache[24] || (_cache[24] = $event => ((_ctx.passwordForm.old_password) = $event)),
                        autocomplete: "current-password"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.passwordForm.old_password]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[113] || (_cache[113] = _createElementVNode("label", { for: "new-password" }, "New password", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "new-password",
                        type: "password",
                        "onUpdate:modelValue": _cache[25] || (_cache[25] = $event => ((_ctx.passwordForm.new_password) = $event)),
                        autocomplete: "new-password"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.passwordForm.new_password]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[114] || (_cache[114] = _createElementVNode("label", { for: "new-password-confirm" }, "Repeat password", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "new-password-confirm",
                        type: "password",
                        "onUpdate:modelValue": _cache[26] || (_cache[26] = $event => ((_ctx.passwordForm.confirm) = $event)),
                        autocomplete: "new-password"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.passwordForm.confirm]
                      ])
                    ])
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[27] || (_cache[27] = (...args) => (_ctx.changePassword && _ctx.changePassword(...args)))
                  }, "Change password"),
                  _cache[123] || (_cache[123] = _createElementVNode("h2", null, "Account MFA", -1 /* CACHED */)),
                  _createElementVNode("p", _hoisted_29, _toDisplayString(_ctx.user.mfa_enabled ? 'MFA enabled' : 'MFA disabled'), 1 /* TEXT */),
                  (!_ctx.user.mfa_enabled)
                    ? (_openBlock(), _createElementBlock(_Fragment, { key: 1 }, [
                        _createElementVNode("button", {
                          class: "primary",
                          onClick: _cache[28] || (_cache[28] = (...args) => (_ctx.setupMfa && _ctx.setupMfa(...args)))
                        }, "Create TOTP secret"),
                        (_ctx.mfaSetup.secret)
                          ? (_openBlock(), _createElementBlock("div", _hoisted_30, [
                              _cache[115] || (_cache[115] = _createElementVNode("label", { for: "mfa-secret" }, "Secret", -1 /* CACHED */)),
                              _createElementVNode("input", {
                                id: "mfa-secret",
                                readonly: "",
                                value: _ctx.mfaSetup.secret
                              }, null, 8 /* PROPS */, _hoisted_31),
                              _cache[116] || (_cache[116] = _createElementVNode("label", { for: "mfa-verify-code" }, "Verify code", -1 /* CACHED */)),
                              _withDirectives(_createElementVNode("input", {
                                id: "mfa-verify-code",
                                "onUpdate:modelValue": _cache[29] || (_cache[29] = $event => ((_ctx.mfaSetup.code) = $event)),
                                inputmode: "numeric",
                                autocomplete: "one-time-code"
                              }, null, 512 /* NEED_PATCH */), [
                                [_vModelText, _ctx.mfaSetup.code]
                              ]),
                              _createElementVNode("button", {
                                class: "primary",
                                onClick: _cache[30] || (_cache[30] = (...args) => (_ctx.enableMfa && _ctx.enableMfa(...args)))
                              }, "Enable MFA"),
                              _createElementVNode("p", _hoisted_32, _toDisplayString(_ctx.mfaSetup.otpauth_url), 1 /* TEXT */)
                            ]))
                          : _createCommentVNode("v-if", true)
                      ], 64 /* STABLE_FRAGMENT */))
                    : (_openBlock(), _createElementBlock(_Fragment, { key: 2 }, [
                        _cache[117] || (_cache[117] = _createElementVNode("label", { for: "mfa-manage-code" }, "TOTP or recovery code", -1 /* CACHED */)),
                        _withDirectives(_createElementVNode("input", {
                          id: "mfa-manage-code",
                          "onUpdate:modelValue": _cache[31] || (_cache[31] = $event => ((_ctx.mfaManageForm.code) = $event)),
                          autocomplete: "one-time-code"
                        }, null, 512 /* NEED_PATCH */), [
                          [_vModelText, _ctx.mfaManageForm.code]
                        ]),
                        _createElementVNode("button", {
                          disabled: _ctx.isBusy,
                          onClick: _cache[32] || (_cache[32] = (...args) => (_ctx.regenerateRecoveryCodes && _ctx.regenerateRecoveryCodes(...args)))
                        }, "Regenerate recovery codes", 8 /* PROPS */, _hoisted_33),
                        _createElementVNode("button", {
                          disabled: _ctx.isBusy,
                          onClick: _cache[33] || (_cache[33] = (...args) => (_ctx.disableMfa && _ctx.disableMfa(...args)))
                        }, "Disable MFA", 8 /* PROPS */, _hoisted_34)
                      ], 64 /* STABLE_FRAGMENT */)),
                  (_ctx.mfaRecoveryCodes.length)
                    ? (_openBlock(), _createElementBlock("pre", _hoisted_35, _toDisplayString(_ctx.mfaRecoveryCodes), 1 /* TEXT */))
                    : _createCommentVNode("v-if", true),
                  _cache[124] || (_cache[124] = _createElementVNode("h2", null, "Active consents", -1 /* CACHED */)),
                  (!_ctx.consents.length)
                    ? (_openBlock(), _createElementBlock("p", _hoisted_36, "No active OAuth consents."))
                    : (_openBlock(), _createElementBlock("div", _hoisted_37, [
                        _createElementVNode("table", null, [
                          _cache[118] || (_cache[118] = _createElementVNode("caption", { class: "sr-only" }, "Active OAuth consents", -1 /* CACHED */)),
                          _cache[119] || (_cache[119] = _createElementVNode("thead", null, [
                            _createElementVNode("tr", null, [
                              _createElementVNode("th", { scope: "col" }, "Application"),
                              _createElementVNode("th", { scope: "col" }, "Scopes"),
                              _createElementVNode("th", { scope: "col" }, "Updated"),
                              _createElementVNode("th", { scope: "col" }, "Action")
                            ])
                          ], -1 /* CACHED */)),
                          _createElementVNode("tbody", null, [
                            (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.consents, (consent) => {
                              return (_openBlock(), _createElementBlock("tr", {
                                key: consent.client_id
                              }, [
                                _createElementVNode("td", null, _toDisplayString(consent.client_name) + " (" + _toDisplayString(consent.client_id) + ")", 1 /* TEXT */),
                                _createElementVNode("td", null, _toDisplayString(consent.scopes.join(' ')), 1 /* TEXT */),
                                _createElementVNode("td", null, _toDisplayString(consent.updated_at), 1 /* TEXT */),
                                _createElementVNode("td", null, [
                                  _createElementVNode("button", {
                                    disabled: _ctx.isBusy,
                                    "aria-label": 'Revoke consent for ' + consent.client_name,
                                    onClick: $event => (_ctx.revokeConsent(consent.client_id))
                                  }, "Revoke", 8 /* PROPS */, _hoisted_38)
                                ])
                              ]))
                            }), 128 /* KEYED_FRAGMENT */))
                          ])
                        ])
                      ]))
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'clients')
              ? (_openBlock(), _createElementBlock("div", _hoisted_39, [
                  _cache[129] || (_cache[129] = _createElementVNode("h1", null, "OAuth clients", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_40, [
                    _createElementVNode("div", null, [
                      _cache[125] || (_cache[125] = _createElementVNode("label", { for: "client-name" }, "Name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "client-name",
                        "onUpdate:modelValue": _cache[34] || (_cache[34] = $event => ((_ctx.clientForm.name) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.clientForm.name]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[126] || (_cache[126] = _createElementVNode("label", { for: "client-id" }, "Client ID", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "client-id",
                        "onUpdate:modelValue": _cache[35] || (_cache[35] = $event => ((_ctx.clientForm.client_id) = $event)),
                        autocomplete: "off"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.clientForm.client_id]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[128] || (_cache[128] = _createElementVNode("label", { for: "client-type" }, "Type", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("select", {
                        id: "client-type",
                        "onUpdate:modelValue": _cache[36] || (_cache[36] = $event => ((_ctx.clientForm.type) = $event))
                      }, [...(_cache[127] || (_cache[127] = [
                        _createElementVNode("option", { value: "confidential" }, "confidential", -1 /* CACHED */),
                        _createElementVNode("option", { value: "public" }, "public", -1 /* CACHED */)
                      ]))], 512 /* NEED_PATCH */), [
                        [_vModelSelect, _ctx.clientForm.type]
                      ])
                    ])
                  ]),
                  _cache[130] || (_cache[130] = _createElementVNode("label", { for: "client-redirects" }, "Redirect URIs, one per line", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("textarea", {
                    id: "client-redirects",
                    rows: "3",
                    "onUpdate:modelValue": _cache[37] || (_cache[37] = $event => ((_ctx.clientForm.redirects) = $event))
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.clientForm.redirects]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[38] || (_cache[38] = (...args) => (_ctx.createClient && _ctx.createClient(...args)))
                  }, "Create client"),
                  _createElementVNode("pre", null, _toDisplayString(_ctx.clients), 1 /* TEXT */)
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'users')
              ? (_openBlock(), _createElementBlock("div", _hoisted_41, [
                  _cache[145] || (_cache[145] = _createElementVNode("h1", null, "Users", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_42, [
                    _createElementVNode("div", null, [
                      _cache[131] || (_cache[131] = _createElementVNode("label", { for: "user-username" }, "Username", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "user-username",
                        "onUpdate:modelValue": _cache[39] || (_cache[39] = $event => ((_ctx.userForm.username) = $event)),
                        autocomplete: "off"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.userForm.username]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[132] || (_cache[132] = _createElementVNode("label", { for: "user-email" }, "Email", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "user-email",
                        type: "email",
                        "onUpdate:modelValue": _cache[40] || (_cache[40] = $event => ((_ctx.userForm.email) = $event)),
                        autocomplete: "off"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.userForm.email]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[133] || (_cache[133] = _createElementVNode("label", { for: "user-password" }, "Password", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "user-password",
                        type: "password",
                        "onUpdate:modelValue": _cache[41] || (_cache[41] = $event => ((_ctx.userForm.password) = $event)),
                        autocomplete: "new-password"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.userForm.password]
                      ])
                    ])
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[42] || (_cache[42] = (...args) => (_ctx.createUser && _ctx.createUser(...args)))
                  }, "Create user"),
                  _createElementVNode("div", _hoisted_43, [
                    _createElementVNode("table", null, [
                      _cache[134] || (_cache[134] = _createElementVNode("caption", { class: "sr-only" }, "Users", -1 /* CACHED */)),
                      _cache[135] || (_cache[135] = _createElementVNode("thead", null, [
                        _createElementVNode("tr", null, [
                          _createElementVNode("th", { scope: "col" }, "Username"),
                          _createElementVNode("th", { scope: "col" }, "Email"),
                          _createElementVNode("th", { scope: "col" }, "Status"),
                          _createElementVNode("th", { scope: "col" }, "Assignments")
                        ])
                      ], -1 /* CACHED */)),
                      _createElementVNode("tbody", null, [
                        (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.users, (item) => {
                          return (_openBlock(), _createElementBlock("tr", {
                            key: item.id
                          }, [
                            _createElementVNode("td", null, _toDisplayString(item.username), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(item.email), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(item.status), 1 /* TEXT */),
                            _createElementVNode("td", null, [
                              _createElementVNode("button", {
                                "aria-label": 'Manage roles and groups for ' + item.username,
                                onClick: $event => (_ctx.selectUser(item))
                              }, "Manage roles/groups", 8 /* PROPS */, _hoisted_44)
                            ])
                          ]))
                        }), 128 /* KEYED_FRAGMENT */))
                      ])
                    ])
                  ]),
                  (_ctx.selectedUser)
                    ? (_openBlock(), _createElementBlock("section", _hoisted_45, [
                        _createElementVNode("h2", null, "User assignments: " + _toDisplayString(_ctx.selectedUser.username), 1 /* TEXT */),
                        _createElementVNode("div", _hoisted_46, [
                          _createElementVNode("div", null, [
                            _cache[138] || (_cache[138] = _createElementVNode("h3", null, "Direct roles", -1 /* CACHED */)),
                            _createElementVNode("div", _hoisted_47, [
                              _cache[137] || (_cache[137] = _createElementVNode("label", {
                                class: "sr-only",
                                for: "user-role-assignment"
                              }, "Role to assign", -1 /* CACHED */)),
                              _withDirectives(_createElementVNode("select", {
                                id: "user-role-assignment",
                                "onUpdate:modelValue": _cache[43] || (_cache[43] = $event => ((_ctx.assignmentForms.userRoleId) = $event))
                              }, [
                                _cache[136] || (_cache[136] = _createElementVNode("option", { value: "" }, "Select role", -1 /* CACHED */)),
                                (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.availableUserRoles, (role) => {
                                  return (_openBlock(), _createElementBlock("option", {
                                    key: role.id,
                                    value: role.id
                                  }, _toDisplayString(role.code), 9 /* TEXT, PROPS */, _hoisted_48))
                                }), 128 /* KEYED_FRAGMENT */))
                              ], 512 /* NEED_PATCH */), [
                                [_vModelSelect, _ctx.assignmentForms.userRoleId]
                              ]),
                              _createElementVNode("button", {
                                class: "primary",
                                onClick: _cache[44] || (_cache[44] = (...args) => (_ctx.assignUserRole && _ctx.assignUserRole(...args)))
                              }, "Assign")
                            ]),
                            _createElementVNode("ul", _hoisted_49, [
                              (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.selectedUserRoles, (role) => {
                                return (_openBlock(), _createElementBlock("li", {
                                  key: role.id
                                }, [
                                  _createTextVNode(_toDisplayString(role.code) + " ", 1 /* TEXT */),
                                  _createElementVNode("button", {
                                    disabled: _ctx.isBusy,
                                    "aria-label": 'Remove role ' + role.code,
                                    onClick: $event => (_ctx.removeUserRole(role.id))
                                  }, "remove", 8 /* PROPS */, _hoisted_50)
                                ]))
                              }), 128 /* KEYED_FRAGMENT */))
                            ])
                          ]),
                          _createElementVNode("div", null, [
                            _cache[141] || (_cache[141] = _createElementVNode("h3", null, "Groups", -1 /* CACHED */)),
                            _createElementVNode("div", _hoisted_51, [
                              _cache[140] || (_cache[140] = _createElementVNode("label", {
                                class: "sr-only",
                                for: "user-group-assignment"
                              }, "Group to assign", -1 /* CACHED */)),
                              _withDirectives(_createElementVNode("select", {
                                id: "user-group-assignment",
                                "onUpdate:modelValue": _cache[45] || (_cache[45] = $event => ((_ctx.assignmentForms.userGroupId) = $event))
                              }, [
                                _cache[139] || (_cache[139] = _createElementVNode("option", { value: "" }, "Select group", -1 /* CACHED */)),
                                (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.availableUserGroups, (group) => {
                                  return (_openBlock(), _createElementBlock("option", {
                                    key: group.id,
                                    value: group.id
                                  }, _toDisplayString(group.code), 9 /* TEXT, PROPS */, _hoisted_52))
                                }), 128 /* KEYED_FRAGMENT */))
                              ], 512 /* NEED_PATCH */), [
                                [_vModelSelect, _ctx.assignmentForms.userGroupId]
                              ]),
                              _createElementVNode("button", {
                                class: "primary",
                                onClick: _cache[46] || (_cache[46] = (...args) => (_ctx.assignUserGroup && _ctx.assignUserGroup(...args)))
                              }, "Assign")
                            ]),
                            _createElementVNode("ul", _hoisted_53, [
                              (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.selectedUserGroups, (group) => {
                                return (_openBlock(), _createElementBlock("li", {
                                  key: group.id
                                }, [
                                  _createTextVNode(_toDisplayString(group.code) + " ", 1 /* TEXT */),
                                  _createElementVNode("button", {
                                    disabled: _ctx.isBusy,
                                    "aria-label": 'Remove group ' + group.code,
                                    onClick: $event => (_ctx.removeUserGroup(group.id))
                                  }, "remove", 8 /* PROPS */, _hoisted_54)
                                ]))
                              }), 128 /* KEYED_FRAGMENT */))
                            ])
                          ])
                        ]),
                        _cache[144] || (_cache[144] = _createElementVNode("h3", null, "Federated identity links", -1 /* CACHED */)),
                        (!_ctx.selectedUserIdentities.length)
                          ? (_openBlock(), _createElementBlock("p", _hoisted_55, "No federated identities."))
                          : (_openBlock(), _createElementBlock("div", _hoisted_56, [
                              _createElementVNode("table", null, [
                                _cache[142] || (_cache[142] = _createElementVNode("caption", { class: "sr-only" }, "Federated identities for selected user", -1 /* CACHED */)),
                                _cache[143] || (_cache[143] = _createElementVNode("thead", null, [
                                  _createElementVNode("tr", null, [
                                    _createElementVNode("th", { scope: "col" }, "Provider"),
                                    _createElementVNode("th", { scope: "col" }, "Subject"),
                                    _createElementVNode("th", { scope: "col" }, "Email"),
                                    _createElementVNode("th", { scope: "col" }, "Action")
                                  ])
                                ], -1 /* CACHED */)),
                                _createElementVNode("tbody", null, [
                                  (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.selectedUserIdentities, (identity) => {
                                    return (_openBlock(), _createElementBlock("tr", {
                                      key: identity.provider_id + ':' + identity.external_subject
                                    }, [
                                      _createElementVNode("td", null, _toDisplayString(identity.provider_name) + " (" + _toDisplayString(identity.provider_code) + ")", 1 /* TEXT */),
                                      _createElementVNode("td", null, _toDisplayString(identity.external_subject), 1 /* TEXT */),
                                      _createElementVNode("td", null, _toDisplayString(identity.email), 1 /* TEXT */),
                                      _createElementVNode("td", null, [
                                        _createElementVNode("button", {
                                          disabled: _ctx.isBusy,
                                          "aria-label": 'Unlink identity from ' + identity.provider_name,
                                          onClick: $event => (_ctx.unlinkFederatedIdentity(identity))
                                        }, "Unlink", 8 /* PROPS */, _hoisted_57)
                                      ])
                                    ]))
                                  }), 128 /* KEYED_FRAGMENT */))
                                ])
                              ])
                            ]))
                      ]))
                    : _createCommentVNode("v-if", true)
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'roles')
              ? (_openBlock(), _createElementBlock("div", _hoisted_58, [
                  _cache[152] || (_cache[152] = _createElementVNode("h1", null, "Roles", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_59, [
                    _createElementVNode("div", null, [
                      _cache[146] || (_cache[146] = _createElementVNode("label", { for: "role-code" }, "Code", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "role-code",
                        "onUpdate:modelValue": _cache[47] || (_cache[47] = $event => ((_ctx.roleForm.code) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.roleForm.code]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[147] || (_cache[147] = _createElementVNode("label", { for: "role-name" }, "Name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "role-name",
                        "onUpdate:modelValue": _cache[48] || (_cache[48] = $event => ((_ctx.roleForm.name) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.roleForm.name]
                      ])
                    ])
                  ]),
                  _cache[153] || (_cache[153] = _createElementVNode("label", { for: "role-description" }, "Description", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("textarea", {
                    id: "role-description",
                    rows: "2",
                    "onUpdate:modelValue": _cache[49] || (_cache[49] = $event => ((_ctx.roleForm.description) = $event))
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.roleForm.description]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[50] || (_cache[50] = (...args) => (_ctx.createRole && _ctx.createRole(...args)))
                  }, "Create role"),
                  _createElementVNode("div", _hoisted_60, [
                    _createElementVNode("table", null, [
                      _cache[148] || (_cache[148] = _createElementVNode("caption", { class: "sr-only" }, "Roles", -1 /* CACHED */)),
                      _cache[149] || (_cache[149] = _createElementVNode("thead", null, [
                        _createElementVNode("tr", null, [
                          _createElementVNode("th", { scope: "col" }, "Code"),
                          _createElementVNode("th", { scope: "col" }, "Name"),
                          _createElementVNode("th", { scope: "col" }, "Description"),
                          _createElementVNode("th", { scope: "col" }, "Permissions")
                        ])
                      ], -1 /* CACHED */)),
                      _createElementVNode("tbody", null, [
                        (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.roles, (role) => {
                          return (_openBlock(), _createElementBlock("tr", {
                            key: role.id
                          }, [
                            _createElementVNode("td", null, _toDisplayString(role.code), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(role.name), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(role.description), 1 /* TEXT */),
                            _createElementVNode("td", null, [
                              _createElementVNode("button", {
                                "aria-label": 'Manage permissions for ' + role.code,
                                onClick: $event => (_ctx.selectRole(role))
                              }, "Manage permissions", 8 /* PROPS */, _hoisted_61)
                            ])
                          ]))
                        }), 128 /* KEYED_FRAGMENT */))
                      ])
                    ])
                  ]),
                  (_ctx.selectedRole)
                    ? (_openBlock(), _createElementBlock("section", _hoisted_62, [
                        _createElementVNode("h2", null, "Role permissions: " + _toDisplayString(_ctx.selectedRole.code), 1 /* TEXT */),
                        _createElementVNode("div", _hoisted_63, [
                          _cache[151] || (_cache[151] = _createElementVNode("label", {
                            class: "sr-only",
                            for: "role-permission-assignment"
                          }, "Permission to assign", -1 /* CACHED */)),
                          _withDirectives(_createElementVNode("select", {
                            id: "role-permission-assignment",
                            "onUpdate:modelValue": _cache[51] || (_cache[51] = $event => ((_ctx.assignmentForms.rolePermissionId) = $event))
                          }, [
                            _cache[150] || (_cache[150] = _createElementVNode("option", { value: "" }, "Select permission", -1 /* CACHED */)),
                            (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.availableRolePermissions, (permission) => {
                              return (_openBlock(), _createElementBlock("option", {
                                key: permission.id,
                                value: permission.id
                              }, _toDisplayString(permission.resource) + ":" + _toDisplayString(permission.action), 9 /* TEXT, PROPS */, _hoisted_64))
                            }), 128 /* KEYED_FRAGMENT */))
                          ], 512 /* NEED_PATCH */), [
                            [_vModelSelect, _ctx.assignmentForms.rolePermissionId]
                          ]),
                          _createElementVNode("button", {
                            class: "primary",
                            onClick: _cache[52] || (_cache[52] = (...args) => (_ctx.assignRolePermission && _ctx.assignRolePermission(...args)))
                          }, "Assign")
                        ]),
                        _createElementVNode("ul", _hoisted_65, [
                          (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.selectedRolePermissions, (permission) => {
                            return (_openBlock(), _createElementBlock("li", {
                              key: permission.id
                            }, [
                              _createTextVNode(_toDisplayString(permission.resource) + ":" + _toDisplayString(permission.action) + " ", 1 /* TEXT */),
                              _createElementVNode("button", {
                                disabled: _ctx.isBusy,
                                "aria-label": 'Remove permission ' + permission.resource + ':' + permission.action,
                                onClick: $event => (_ctx.removeRolePermission(permission.id))
                              }, "remove", 8 /* PROPS */, _hoisted_66)
                            ]))
                          }), 128 /* KEYED_FRAGMENT */))
                        ])
                      ]))
                    : _createCommentVNode("v-if", true)
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'groups')
              ? (_openBlock(), _createElementBlock("div", _hoisted_67, [
                  _cache[160] || (_cache[160] = _createElementVNode("h1", null, "Groups", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_68, [
                    _createElementVNode("div", null, [
                      _cache[154] || (_cache[154] = _createElementVNode("label", { for: "group-code" }, "Code", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "group-code",
                        "onUpdate:modelValue": _cache[53] || (_cache[53] = $event => ((_ctx.groupForm.code) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.groupForm.code]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[155] || (_cache[155] = _createElementVNode("label", { for: "group-name" }, "Name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "group-name",
                        "onUpdate:modelValue": _cache[54] || (_cache[54] = $event => ((_ctx.groupForm.name) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.groupForm.name]
                      ])
                    ])
                  ]),
                  _cache[161] || (_cache[161] = _createElementVNode("label", { for: "group-description" }, "Description", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("textarea", {
                    id: "group-description",
                    rows: "2",
                    "onUpdate:modelValue": _cache[55] || (_cache[55] = $event => ((_ctx.groupForm.description) = $event))
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.groupForm.description]
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[56] || (_cache[56] = (...args) => (_ctx.createGroup && _ctx.createGroup(...args)))
                  }, "Create group"),
                  _createElementVNode("div", _hoisted_69, [
                    _createElementVNode("table", null, [
                      _cache[156] || (_cache[156] = _createElementVNode("caption", { class: "sr-only" }, "Groups", -1 /* CACHED */)),
                      _cache[157] || (_cache[157] = _createElementVNode("thead", null, [
                        _createElementVNode("tr", null, [
                          _createElementVNode("th", { scope: "col" }, "Code"),
                          _createElementVNode("th", { scope: "col" }, "Name"),
                          _createElementVNode("th", { scope: "col" }, "Description"),
                          _createElementVNode("th", { scope: "col" }, "Roles")
                        ])
                      ], -1 /* CACHED */)),
                      _createElementVNode("tbody", null, [
                        (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.groups, (group) => {
                          return (_openBlock(), _createElementBlock("tr", {
                            key: group.id
                          }, [
                            _createElementVNode("td", null, _toDisplayString(group.code), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(group.name), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(group.description), 1 /* TEXT */),
                            _createElementVNode("td", null, [
                              _createElementVNode("button", {
                                "aria-label": 'Manage roles for ' + group.code,
                                onClick: $event => (_ctx.selectGroup(group))
                              }, "Manage roles", 8 /* PROPS */, _hoisted_70)
                            ])
                          ]))
                        }), 128 /* KEYED_FRAGMENT */))
                      ])
                    ])
                  ]),
                  (_ctx.selectedGroup)
                    ? (_openBlock(), _createElementBlock("section", _hoisted_71, [
                        _createElementVNode("h2", null, "Group roles: " + _toDisplayString(_ctx.selectedGroup.code), 1 /* TEXT */),
                        _createElementVNode("div", _hoisted_72, [
                          _cache[159] || (_cache[159] = _createElementVNode("label", {
                            class: "sr-only",
                            for: "group-role-assignment"
                          }, "Role to assign", -1 /* CACHED */)),
                          _withDirectives(_createElementVNode("select", {
                            id: "group-role-assignment",
                            "onUpdate:modelValue": _cache[57] || (_cache[57] = $event => ((_ctx.assignmentForms.groupRoleId) = $event))
                          }, [
                            _cache[158] || (_cache[158] = _createElementVNode("option", { value: "" }, "Select role", -1 /* CACHED */)),
                            (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.availableGroupRoles, (role) => {
                              return (_openBlock(), _createElementBlock("option", {
                                key: role.id,
                                value: role.id
                              }, _toDisplayString(role.code), 9 /* TEXT, PROPS */, _hoisted_73))
                            }), 128 /* KEYED_FRAGMENT */))
                          ], 512 /* NEED_PATCH */), [
                            [_vModelSelect, _ctx.assignmentForms.groupRoleId]
                          ]),
                          _createElementVNode("button", {
                            class: "primary",
                            onClick: _cache[58] || (_cache[58] = (...args) => (_ctx.assignGroupRole && _ctx.assignGroupRole(...args)))
                          }, "Assign")
                        ]),
                        _createElementVNode("ul", _hoisted_74, [
                          (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.selectedGroupRoles, (role) => {
                            return (_openBlock(), _createElementBlock("li", {
                              key: role.id
                            }, [
                              _createTextVNode(_toDisplayString(role.code) + " ", 1 /* TEXT */),
                              _createElementVNode("button", {
                                disabled: _ctx.isBusy,
                                "aria-label": 'Remove role ' + role.code,
                                onClick: $event => (_ctx.removeGroupRole(role.id))
                              }, "remove", 8 /* PROPS */, _hoisted_75)
                            ]))
                          }), 128 /* KEYED_FRAGMENT */))
                        ])
                      ]))
                    : _createCommentVNode("v-if", true)
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'audit')
              ? (_openBlock(), _createElementBlock("div", _hoisted_76, [
                  _cache[162] || (_cache[162] = _createElementVNode("h1", null, "Audit", -1 /* CACHED */)),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[59] || (_cache[59] = (...args) => (_ctx.loadAudit && _ctx.loadAudit(...args)))
                  }, "Refresh audit"),
                  _createElementVNode("pre", null, _toDisplayString(_ctx.audit), 1 /* TEXT */)
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'ldap')
              ? (_openBlock(), _createElementBlock("div", _hoisted_77, [
                  _cache[172] || (_cache[172] = _createElementVNode("h1", null, "LDAP providers", -1 /* CACHED */)),
                  _createElementVNode("div", _hoisted_78, [
                    _createElementVNode("div", null, [
                      _cache[163] || (_cache[163] = _createElementVNode("label", { for: "ldap-name" }, "Name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "ldap-name",
                        "onUpdate:modelValue": _cache[60] || (_cache[60] = $event => ((_ctx.ldapForm.name) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.ldapForm.name]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[164] || (_cache[164] = _createElementVNode("label", { for: "ldap-host" }, "Host", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "ldap-host",
                        "onUpdate:modelValue": _cache[61] || (_cache[61] = $event => ((_ctx.ldapForm.host) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.ldapForm.host]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[165] || (_cache[165] = _createElementVNode("label", { for: "ldap-port" }, "Port", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "ldap-port",
                        type: "number",
                        "onUpdate:modelValue": _cache[62] || (_cache[62] = $event => ((_ctx.ldapForm.port) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [
                          _vModelText,
                          _ctx.ldapForm.port,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[166] || (_cache[166] = _createElementVNode("label", { for: "ldap-bind-dn" }, "Bind DN", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "ldap-bind-dn",
                        "onUpdate:modelValue": _cache[63] || (_cache[63] = $event => ((_ctx.ldapForm.bind_dn) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.ldapForm.bind_dn]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[167] || (_cache[167] = _createElementVNode("label", { for: "ldap-bind-password" }, "Bind password", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "ldap-bind-password",
                        type: "password",
                        "onUpdate:modelValue": _cache[64] || (_cache[64] = $event => ((_ctx.ldapForm.bind_password) = $event)),
                        autocomplete: "new-password"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.ldapForm.bind_password]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[168] || (_cache[168] = _createElementVNode("label", { for: "ldap-base-dn" }, "Base DN", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "ldap-base-dn",
                        "onUpdate:modelValue": _cache[65] || (_cache[65] = $event => ((_ctx.ldapForm.base_dn) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.ldapForm.base_dn]
                      ])
                    ])
                  ]),
                  _cache[173] || (_cache[173] = _createElementVNode("label", { for: "ldap-user-filter" }, "User filter", -1 /* CACHED */)),
                  _withDirectives(_createElementVNode("input", {
                    id: "ldap-user-filter",
                    "onUpdate:modelValue": _cache[66] || (_cache[66] = $event => ((_ctx.ldapForm.user_filter) = $event))
                  }, null, 512 /* NEED_PATCH */), [
                    [_vModelText, _ctx.ldapForm.user_filter]
                  ]),
                  _createElementVNode("div", _hoisted_79, [
                    _createElementVNode("label", null, [
                      _withDirectives(_createElementVNode("input", {
                        type: "checkbox",
                        "onUpdate:modelValue": _cache[67] || (_cache[67] = $event => ((_ctx.ldapForm.use_tls) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelCheckbox, _ctx.ldapForm.use_tls]
                      ]),
                      _cache[169] || (_cache[169] = _createTextVNode(" Use TLS", -1 /* CACHED */))
                    ]),
                    _createElementVNode("label", null, [
                      _withDirectives(_createElementVNode("input", {
                        type: "checkbox",
                        "onUpdate:modelValue": _cache[68] || (_cache[68] = $event => ((_ctx.ldapForm.start_tls) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelCheckbox, _ctx.ldapForm.start_tls]
                      ]),
                      _cache[170] || (_cache[170] = _createTextVNode(" StartTLS", -1 /* CACHED */))
                    ]),
                    _createElementVNode("label", null, [
                      _withDirectives(_createElementVNode("input", {
                        type: "checkbox",
                        "onUpdate:modelValue": _cache[69] || (_cache[69] = $event => ((_ctx.ldapForm.enabled) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelCheckbox, _ctx.ldapForm.enabled]
                      ]),
                      _cache[171] || (_cache[171] = _createTextVNode(" Enabled", -1 /* CACHED */))
                    ])
                  ]),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[70] || (_cache[70] = (...args) => (_ctx.testLdap && _ctx.testLdap(...args)))
                  }, "Test"),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[71] || (_cache[71] = (...args) => (_ctx.createLdapProvider && _ctx.createLdapProvider(...args)))
                  }, "Create provider"),
                  _createElementVNode("pre", null, _toDisplayString(_ctx.ldapProviders), 1 /* TEXT */)
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'identity')
              ? (_openBlock(), _createElementBlock("div", _hoisted_80, [
                  _cache[187] || (_cache[187] = _createElementVNode("h1", null, "Identity providers", -1 /* CACHED */)),
                  _createElementVNode("p", _hoisted_81, "Register callback URL as " + _toDisplayString(_ctx.discovery.issuer) + "/oauth2/broker/{code}/callback.", 1 /* TEXT */),
                  _createElementVNode("div", _hoisted_82, [
                    _createElementVNode("div", null, [
                      _cache[174] || (_cache[174] = _createElementVNode("label", { for: "identity-code" }, "Code", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "identity-code",
                        "onUpdate:modelValue": _cache[72] || (_cache[72] = $event => ((_ctx.identityForm.code) = $event)),
                        disabled: !!_ctx.identityForm.id
                      }, null, 8 /* PROPS */, _hoisted_83), [
                        [_vModelText, _ctx.identityForm.code]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[175] || (_cache[175] = _createElementVNode("label", { for: "identity-name" }, "Name", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "identity-name",
                        "onUpdate:modelValue": _cache[73] || (_cache[73] = $event => ((_ctx.identityForm.name) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.identityForm.name]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[177] || (_cache[177] = _createElementVNode("label", { for: "identity-type" }, "Type", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("select", {
                        id: "identity-type",
                        "onUpdate:modelValue": _cache[74] || (_cache[74] = $event => ((_ctx.identityForm.type) = $event))
                      }, [...(_cache[176] || (_cache[176] = [
                        _createElementVNode("option", { value: "google" }, "google", -1 /* CACHED */),
                        _createElementVNode("option", { value: "github" }, "github", -1 /* CACHED */),
                        _createElementVNode("option", { value: "oidc" }, "oidc", -1 /* CACHED */)
                      ]))], 512 /* NEED_PATCH */), [
                        [_vModelSelect, _ctx.identityForm.type]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[178] || (_cache[178] = _createElementVNode("label", { for: "identity-client-id" }, "Client ID", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "identity-client-id",
                        "onUpdate:modelValue": _cache[75] || (_cache[75] = $event => ((_ctx.identityForm.client_id) = $event)),
                        autocomplete: "off"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.identityForm.client_id]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[179] || (_cache[179] = _createElementVNode("label", { for: "identity-client-secret" }, "Client secret", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "identity-client-secret",
                        type: "password",
                        "onUpdate:modelValue": _cache[76] || (_cache[76] = $event => ((_ctx.identityForm.client_secret) = $event)),
                        autocomplete: "new-password"
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.identityForm.client_secret]
                      ])
                    ]),
                    _createElementVNode("div", null, [
                      _cache[180] || (_cache[180] = _createElementVNode("label", { for: "identity-scopes" }, "Scopes", -1 /* CACHED */)),
                      _withDirectives(_createElementVNode("input", {
                        id: "identity-scopes",
                        "onUpdate:modelValue": _cache[77] || (_cache[77] = $event => ((_ctx.identityForm.scopes) = $event))
                      }, null, 512 /* NEED_PATCH */), [
                        [_vModelText, _ctx.identityForm.scopes]
                      ])
                    ])
                  ]),
                  _createElementVNode("label", null, [
                    _withDirectives(_createElementVNode("input", {
                      type: "checkbox",
                      "onUpdate:modelValue": _cache[78] || (_cache[78] = $event => ((_ctx.identityForm.enabled) = $event))
                    }, null, 512 /* NEED_PATCH */), [
                      [_vModelCheckbox, _ctx.identityForm.enabled]
                    ]),
                    _cache[181] || (_cache[181] = _createTextVNode(" Enabled", -1 /* CACHED */))
                  ]),
                  (_ctx.identityForm.type === 'oidc')
                    ? (_openBlock(), _createElementBlock("div", _hoisted_84, [
                        _createElementVNode("div", null, [
                          _cache[182] || (_cache[182] = _createElementVNode("label", { for: "identity-authorize-url" }, "Authorize URL", -1 /* CACHED */)),
                          _withDirectives(_createElementVNode("input", {
                            id: "identity-authorize-url",
                            type: "url",
                            "onUpdate:modelValue": _cache[79] || (_cache[79] = $event => ((_ctx.identityForm.authorize_url) = $event))
                          }, null, 512 /* NEED_PATCH */), [
                            [_vModelText, _ctx.identityForm.authorize_url]
                          ])
                        ]),
                        _createElementVNode("div", null, [
                          _cache[183] || (_cache[183] = _createElementVNode("label", { for: "identity-token-url" }, "Token URL", -1 /* CACHED */)),
                          _withDirectives(_createElementVNode("input", {
                            id: "identity-token-url",
                            type: "url",
                            "onUpdate:modelValue": _cache[80] || (_cache[80] = $event => ((_ctx.identityForm.token_url) = $event))
                          }, null, 512 /* NEED_PATCH */), [
                            [_vModelText, _ctx.identityForm.token_url]
                          ])
                        ]),
                        _createElementVNode("div", null, [
                          _cache[184] || (_cache[184] = _createElementVNode("label", { for: "identity-userinfo-url" }, "Userinfo URL", -1 /* CACHED */)),
                          _withDirectives(_createElementVNode("input", {
                            id: "identity-userinfo-url",
                            type: "url",
                            "onUpdate:modelValue": _cache[81] || (_cache[81] = $event => ((_ctx.identityForm.userinfo_url) = $event))
                          }, null, 512 /* NEED_PATCH */), [
                            [_vModelText, _ctx.identityForm.userinfo_url]
                          ])
                        ])
                      ]))
                    : _createCommentVNode("v-if", true),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[82] || (_cache[82] = (...args) => (_ctx.saveIdentityProvider && _ctx.saveIdentityProvider(...args)))
                  }, _toDisplayString(_ctx.identityForm.id ? 'Update provider' : 'Create provider'), 1 /* TEXT */),
                  _createElementVNode("button", {
                    onClick: _cache[83] || (_cache[83] = (...args) => (_ctx.resetIdentityForm && _ctx.resetIdentityForm(...args)))
                  }, "Reset form"),
                  _createElementVNode("div", _hoisted_85, [
                    _createElementVNode("table", null, [
                      _cache[185] || (_cache[185] = _createElementVNode("caption", { class: "sr-only" }, "Identity providers", -1 /* CACHED */)),
                      _cache[186] || (_cache[186] = _createElementVNode("thead", null, [
                        _createElementVNode("tr", null, [
                          _createElementVNode("th", { scope: "col" }, "Code"),
                          _createElementVNode("th", { scope: "col" }, "Name"),
                          _createElementVNode("th", { scope: "col" }, "Type"),
                          _createElementVNode("th", { scope: "col" }, "Client ID"),
                          _createElementVNode("th", { scope: "col" }, "Enabled"),
                          _createElementVNode("th", { scope: "col" }, "Actions")
                        ])
                      ], -1 /* CACHED */)),
                      _createElementVNode("tbody", null, [
                        (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.identityProviders, (provider) => {
                          return (_openBlock(), _createElementBlock("tr", {
                            key: provider.id
                          }, [
                            _createElementVNode("td", null, _toDisplayString(provider.code), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(provider.name), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(provider.type), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(provider.client_id), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(provider.enabled ? 'yes' : 'no'), 1 /* TEXT */),
                            _createElementVNode("td", null, [
                              _createElementVNode("button", {
                                "aria-label": 'Edit identity provider ' + provider.name,
                                onClick: $event => (_ctx.editIdentityProvider(provider))
                              }, "Edit", 8 /* PROPS */, _hoisted_86),
                              _createElementVNode("button", {
                                disabled: _ctx.isBusy,
                                "aria-label": 'Delete identity provider ' + provider.name,
                                onClick: $event => (_ctx.deleteIdentityProvider(provider))
                              }, "Delete", 8 /* PROPS */, _hoisted_87)
                            ])
                          ]))
                        }), 128 /* KEYED_FRAGMENT */))
                      ])
                    ])
                  ])
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.view === 'operations')
              ? (_openBlock(), _createElementBlock("div", _hoisted_88, [
                  _cache[195] || (_cache[195] = _createElementVNode("h1", null, "Operational status", -1 /* CACHED */)),
                  _createElementVNode("button", {
                    class: "primary",
                    onClick: _cache[84] || (_cache[84] = (...args) => (_ctx.loadOperations && _ctx.loadOperations(...args)))
                  }, "Refresh status"),
                  (_ctx.operationalStatus.status)
                    ? (_openBlock(), _createElementBlock("div", _hoisted_89, [
                        _createElementVNode("div", _hoisted_90, [
                          _cache[188] || (_cache[188] = _createElementVNode("strong", null, "Overall", -1 /* CACHED */)),
                          _createElementVNode("p", _hoisted_91, _toDisplayString(_ctx.operationalStatus.status), 1 /* TEXT */)
                        ]),
                        _createElementVNode("div", _hoisted_92, [
                          _cache[189] || (_cache[189] = _createElementVNode("strong", null, "Database", -1 /* CACHED */)),
                          _createElementVNode("p", _hoisted_93, _toDisplayString(_ctx.operationalStatus.database?.status || 'degraded'), 1 /* TEXT */)
                        ]),
                        _createElementVNode("div", _hoisted_94, [
                          _cache[190] || (_cache[190] = _createElementVNode("strong", null, "Signing", -1 /* CACHED */)),
                          _createElementVNode("p", _hoisted_95, _toDisplayString(_ctx.operationalStatus.signing_keys?.status || 'degraded'), 1 /* TEXT */)
                        ]),
                        _createElementVNode("div", _hoisted_96, [
                          _cache[191] || (_cache[191] = _createElementVNode("strong", null, "Cleanup", -1 /* CACHED */)),
                          _createElementVNode("p", _hoisted_97, [
                            _createTextVNode(_toDisplayString(_ctx.operationalStatus.cleanup?.status || 'degraded') + " · " + _toDisplayString(_ctx.operationalStatus.cleanup?.last_success_at || 'not recorded'), 1 /* TEXT */),
                            (_ctx.operationalStatus.cleanup?.age_seconds !== null && _ctx.operationalStatus.cleanup?.age_seconds !== undefined)
                              ? (_openBlock(), _createElementBlock(_Fragment, { key: 0 }, [
                                  _createTextVNode(" · " + _toDisplayString(_ctx.operationalStatus.cleanup.age_seconds) + "s ago", 1 /* TEXT */)
                                ], 64 /* STABLE_FRAGMENT */))
                              : _createCommentVNode("v-if", true)
                          ])
                        ]),
                        _createElementVNode("div", _hoisted_98, [
                          _cache[192] || (_cache[192] = _createElementVNode("strong", null, "Version", -1 /* CACHED */)),
                          _createElementVNode("p", _hoisted_99, _toDisplayString(_ctx.operationalStatus.version) + " / " + _toDisplayString(_ctx.operationalStatus.environment), 1 /* TEXT */)
                        ])
                      ]))
                    : _createCommentVNode("v-if", true),
                  _cache[196] || (_cache[196] = _createElementVNode("h2", null, "OIDC signing keys", -1 /* CACHED */)),
                  _createElementVNode("button", {
                    class: "primary",
                    disabled: _ctx.isBusy,
                    onClick: _cache[85] || (_cache[85] = (...args) => (_ctx.rotateSigningKey && _ctx.rotateSigningKey(...args)))
                  }, "Rotate signing key", 8 /* PROPS */, _hoisted_100),
                  _createElementVNode("div", _hoisted_101, [
                    _createElementVNode("table", null, [
                      _cache[193] || (_cache[193] = _createElementVNode("caption", { class: "sr-only" }, "OIDC signing keys", -1 /* CACHED */)),
                      _cache[194] || (_cache[194] = _createElementVNode("thead", null, [
                        _createElementVNode("tr", null, [
                          _createElementVNode("th", { scope: "col" }, "KID"),
                          _createElementVNode("th", { scope: "col" }, "Algorithm"),
                          _createElementVNode("th", { scope: "col" }, "Status"),
                          _createElementVNode("th", { scope: "col" }, "Created"),
                          _createElementVNode("th", { scope: "col" }, "Expires")
                        ])
                      ], -1 /* CACHED */)),
                      _createElementVNode("tbody", null, [
                        (_openBlock(true), _createElementBlock(_Fragment, null, _renderList(_ctx.signingKeys, (key) => {
                          return (_openBlock(), _createElementBlock("tr", {
                            key: key.kid
                          }, [
                            _createElementVNode("td", null, _toDisplayString(key.kid), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(key.alg), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(key.status), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(key.created_at), 1 /* TEXT */),
                            _createElementVNode("td", null, _toDisplayString(key.expires_at || '—'), 1 /* TEXT */)
                          ]))
                        }), 128 /* KEYED_FRAGMENT */))
                      ])
                    ])
                  ])
                ]))
              : _createCommentVNode("v-if", true),
            (_ctx.isBusy)
              ? (_openBlock(), _createElementBlock("p", _hoisted_102, "Loading…"))
              : _createCommentVNode("v-if", true),
            (_ctx.errorMessage)
              ? (_openBlock(), _createElementBlock("p", _hoisted_103, _toDisplayString(_ctx.errorMessage), 1 /* TEXT */))
              : _createCommentVNode("v-if", true),
            _createElementVNode("p", _hoisted_104, _toDisplayString(_ctx.message), 1 /* TEXT */)
          ], 8 /* PROPS */, _hoisted_4)
        ])
      ], 64 /* STABLE_FRAGMENT */))
    }
  }(global.Vue));
}(window));
