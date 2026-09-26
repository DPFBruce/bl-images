/* (BL) Images — adds a "Find free images" tab to every wp.media frame
 * (Gutenberg, Elementor, (BL) Slide Editor, (BL) Awards Dinners, featured image…).
 * Imported images land in the library and are selected immediately. */
(function () {
  'use strict';
  if (!window.wp || !wp.media || !wp.media.view || !window.BLImages) return;

  function extend(Frame) {
    if (!Frame || Frame.__blImages) return Frame;
    var Ext = Frame.extend({
      browseRouter: function (routerView) {
        Frame.prototype.browseRouter.apply(this, arguments);
        routerView.set({ blimages: { text: '(BL) Images — find free images', priority: 60 } });
      },
      bindHandlers: function () {
        Frame.prototype.bindHandlers.apply(this, arguments);
        this.on('content:create:blimages', this.blImagesContent, this);
      },
      blImagesContent: function (region) {
        var frame = this;
        var view = new wp.media.View({ className: 'bl-img-modal-pane' });
        region.view = view;
        setTimeout(function () {
          window.BLImages.mount(view.el, {
            view: 'find',
            embedded: true,
            onImported: function (atts) { select(frame, atts.map(function (a) { return a.id; })); },
            onPickExisting: function (id) { select(frame, [id]); }
          });
        }, 0);
      }
    });
    Ext.__blImages = true;
    return Ext;
  }

  function select(frame, ids) {
    var state = frame.state();
    var selection = state && state.get('selection');
    var library = state && state.get('library');
    ids.forEach(function (id) {
      var att = wp.media.attachment(id);
      att.fetch().then(function () {
        if (library) library.add(att, { at: 0 });
        if (selection) {
          if (!selection.multiple) selection.reset([]);
          selection.add(att);
        }
      });
    });
    // Back to the library grid so the user sees the selection and can Insert/Select.
    setTimeout(function () { frame.content.mode('browse'); }, 400);
  }

  var M = wp.media.view.MediaFrame;
  M.Select = extend(M.Select);
  M.Post = extend(M.Post);
})();
