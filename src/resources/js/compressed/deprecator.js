!function(a){/**
 * Deprecator class
 */
var b=Garnish.Base.extend({$clearAllBtn:null,$table:null,tracesModal:null,$tracesModalBody:null,init:function(){this.$clearAllBtn=a("#clearall"),this.$table=a("#deprecationerrors"),this.$noLogsMessage=a("#nologs"),this.addListener(this.$clearAllBtn,"click","clearAllLogs"),this.addListener(this.$table.find(".viewtraces"),"click","viewLogTraces"),this.addListener(this.$table.find(".delete"),"click","deleteLog")},clearAllLogs:function(){Craft.postActionRequest("utils/delete-all-deprecation-errors"),this.onClearAll()},viewLogTraces:function(b){if(this.tracesModal)this.tracesModal.$container.addClass("loading"),this.$tracesModalBody.empty(),this.tracesModal.show();else{var c=a('<div id="traces" class="modal loading"/>').appendTo(Garnish.$bod);this.$tracesModalBody=a('<div class="body"/>').appendTo(c),this.tracesModal=new Garnish.Modal(c,{resizable:!0})}var d={logId:a(b.currentTarget).closest("tr").data("id")};Craft.postActionRequest("utils/get-deprecation-error-traces-modal",d,a.proxy(function(a,b){this.tracesModal.$container.removeClass("loading"),"success"==b&&this.$tracesModalBody.html(a)},this))},deleteLog:function(b){var c=a(b.currentTarget).closest("tr"),d={logId:c.data("id")};Craft.postActionRequest("utils/delete-deprecation-error",d),c.siblings().length?c.remove():this.onClearAll()},onClearAll:function(){this.$clearAllBtn.parent().remove(),this.$table.remove(),this.$noLogsMessage.removeClass("hidden")}});new b}(jQuery);
//# sourceMappingURL=deprecator.js.map