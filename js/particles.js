particlesJS(
  'js-particles', {
    'particles': {
      'number': {
        'value': 300
      },
        'color': {
          'value': ['#ff1e1e', '#ff7b7b', '#cfcfcf']
        },
      'shape': {
        'type':  ["circle","square","polygon"],
      },
      'opacity': {
        'value': 1,
        'random': false,
        'anim': {
          'enable': false
        }
      },
      'size': {
        'value': 2.5,
        'random': true,
        'anim': {
          'enable': false
        }
      },
      'line_linked': {
        'enable': false
      },
      'move': {
        'enable': true,
        'speed': 2,
        'direction': 'none',
        'random': true,
        'straight': false,
        'out_mode': 'out'
      }
    },
    'interactivity': {
      'detect_on': 'canvas',
      'events': {
        'onhover': {
          'enable': false
        },
        'onclick': {
          'enable': false
        },
        'resize': true
      }
    },
    'retina_detect': true
});